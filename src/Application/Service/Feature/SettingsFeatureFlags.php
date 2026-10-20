<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Service\Feature;

use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Environment;
use Semitexa\Core\Feature\FeatureFlag;
use Semitexa\Core\Feature\FeatureFlagRegistry;
use Semitexa\Core\Feature\FeatureFlagsInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * Feature flags with overrides kept in platform settings (module
 * `feature_flags`, the flag name as key): for one user, for one site, or for
 * every site (the `default` tenant row). The most specific answer wins —
 * user, then site, then global, then the declaration. A value is true, false,
 * or {"percent": N} for a rollout to N % of users (of sites, for a guest).
 *
 * Site and global rows come from the store's worker snapshot, so a flip
 * reaches every worker within its 2 s TTL; a user's rows are read once per
 * request. A store that fails answers the declared default: a flag check never
 * takes a page down.
 */
#[ExecutionScoped]
#[SatisfiesServiceContract(of: FeatureFlagsInterface::class)]
final class SettingsFeatureFlags implements FeatureFlagsInterface
{
    #[InjectAsReadonly]
    protected FeatureFlagRegistry $registry;

    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenants;

    #[InjectAsMutable]
    protected AuthContextInterface $auth;

    private ?FeatureFlagStores $stores = null;
    private bool $pinned = false;
    private ?string $tenantId = null;
    private ?string $userId = null;

    /** @var array<string, array<string, mixed>> per tenant|user, this request */
    private array $userRows = [];

    /** @var array<string, true> */
    #[WorkerState('Undeclared flags this worker already reported: one warning each, not one per check.')]
    private static array $warned = [];

    public static function of(FeatureFlagRegistry $registry, SettingsStoreInterface $settings): self
    {
        $flags = new self();
        $flags->registry = $registry;
        $flags->settings = $settings;

        return $flags;
    }

    public function isEnabled(string $flag): bool
    {
        $declared = $this->registry->get($flag);
        if ($declared === null) {
            $this->warnUndeclared($flag);

            return false;
        }

        $tenantId = $this->tenantId() ?? FeatureFlagStores::GLOBAL_TENANT;
        $userId = $this->userId();
        $subject = $userId ?? ($tenantId !== FeatureFlagStores::GLOBAL_TENANT ? $tenantId : null);

        try {
            $value = $this->override($flag, $tenantId, $userId);
        } catch (\Throwable $e) {
            StaticLoggerBridge::warning('feature', 'Feature flag overrides could not be read; the declared default answers', [
                'flag' => $flag,
                'error' => $e->getMessage(),
            ]);

            return $declared->defaultFor($subject);
        }

        return match (true) {
            is_bool($value) => $value,
            is_array($value) && isset($value['percent']) && is_numeric($value['percent'])
                => $subject !== null && FeatureFlag::rollsOutTo($flag, (int) $value['percent'], $subject),
            default => $declared->defaultFor($subject),
        };
    }

    public function forSubject(?string $tenantId, ?string $userId): FeatureFlagsInterface
    {
        $pinned = clone $this;
        $pinned->pinned = true;
        $pinned->tenantId = $tenantId;
        $pinned->userId = $userId;
        $pinned->userRows = [];

        return $pinned;
    }

    /** The most specific stored value, or null when nothing overrides the declaration. */
    private function override(string $flag, string $tenantId, ?string $userId): mixed
    {
        $stores = $this->stores ??= new FeatureFlagStores($this->settings);

        if ($userId !== null) {
            $rows = $this->userRows[$tenantId . "\0" . $userId]
                ??= $stores->for($tenantId)->getAllForUser(FeatureFlagStores::MODULE, $userId);
            if (($rows[$flag] ?? null) !== null) {
                return $rows[$flag];
            }
        }
        if ($tenantId !== FeatureFlagStores::GLOBAL_TENANT) {
            $site = $stores->for($tenantId)->getAll(FeatureFlagStores::MODULE)[$flag] ?? null;
            if ($site !== null) {
                return $site;
            }
        }

        return $stores->for(FeatureFlagStores::GLOBAL_TENANT)->getAll(FeatureFlagStores::MODULE)[$flag] ?? null;
    }

    private function userId(): ?string
    {
        if ($this->pinned) {
            return $this->userId !== '' ? $this->userId : null;
        }
        $id = isset($this->auth) && !$this->auth->isGuest() ? $this->auth->getUser()?->getId() : null;

        return $id !== null && $id !== '' ? $id : null;
    }

    private function tenantId(): ?string
    {
        if ($this->pinned) {
            return $this->tenantId !== '' ? $this->tenantId : null;
        }

        return isset($this->tenants) ? TenantContextAccess::tenantId($this->tenants->tryGet()) : null;
    }

    private function warnUndeclared(string $flag): void
    {
        if (isset(self::$warned[$flag]) || Environment::getEnvValue('APP_ENV', 'prod') === 'prod') {
            return;
        }
        self::$warned[$flag] = true;
        StaticLoggerBridge::warning('feature', 'A feature flag nobody declared was checked; it is off', ['flag' => $flag]);
    }
}
