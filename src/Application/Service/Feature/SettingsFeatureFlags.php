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

    /** Optional: a command or a job has no request, and asks forSubject() instead. */
    #[InjectAsMutable(optional: true)]
    protected AuthContextInterface $auth;

    private ?FeatureFlagStores $stores = null;
    private bool $pinned = false;
    private ?string $tenantId = null;
    private ?string $userId = null;

    /** @var array<string, array<string, mixed>> per tenant|user, this request */
    private array $userRows = [];

    /** @var array<string, \Throwable> */
    private array $userReadFailures = [];

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
        return $this->explain($flag)['enabled'];
    }

    /**
     * The answer and where it came from: `user`, `site`, `global`, `declared`,
     * `undeclared`, or `fallback` when the store could not be read.
     *
     * @return array{enabled: bool, source: string, value: mixed}
     */
    public function explain(string $flag): array
    {
        $declared = $this->registry->get($flag);
        if ($declared === null) {
            $this->warnUndeclared($flag);

            return ['enabled' => false, 'source' => 'undeclared', 'value' => null];
        }

        $tenantId = $this->tenantId() ?? FeatureFlagStores::GLOBAL_TENANT;
        $userId = $this->userId();
        // A guest is decided by site; on a single-site install the site is `default`.
        $subject = $userId ?? $tenantId;

        try {
            [$source, $value] = $this->override($flag, $tenantId, $userId);
        } catch (\Throwable $e) {
            StaticLoggerBridge::warning('feature', 'Feature flag overrides could not be read; the declared default answers', [
                'flag' => $flag,
                'error' => $e->getMessage(),
            ]);

            return ['enabled' => $declared->defaultFor($subject), 'source' => 'fallback', 'value' => null];
        }

        $enabled = match (true) {
            is_bool($value) => $value,
            self::isRollout($value) => FeatureFlag::rollsOutTo($flag, (int) $value['percent'], $subject),
            default => null,
        };

        return $enabled === null
            ? ['enabled' => $declared->defaultFor($subject), 'source' => 'declared', 'value' => $declared->percent !== null ? ['percent' => $declared->percent] : $declared->default]
            : ['enabled' => $enabled, 'source' => $source, 'value' => $value];
    }

    public function forSubject(?string $tenantId, ?string $userId): FeatureFlagsInterface
    {
        $pinned = clone $this;
        $pinned->pinned = true;
        $pinned->tenantId = $tenantId;
        $pinned->userId = $userId;
        $pinned->userRows = [];
        $pinned->userReadFailures = [];

        return $pinned;
    }

    /**
     * The most specific stored value and its scope; [declared, null] when
     * nothing overrides the declaration.
     *
     * @return array{0: string, 1: mixed}
     */
    private function override(string $flag, string $tenantId, ?string $userId): array
    {
        $stores = $this->stores ??= new FeatureFlagStores($this->settings);

        if ($userId !== null) {
            $key = $tenantId . "\0" . $userId;
            // A failed read is remembered for the request and thrown again:
            // with the store down every flag check tried again and waited out
            // a connect timeout, on every signed-in page. Rethrown, not taken
            // as "no overrides", so every answer stays the declared default
            // rather than a site value that ignores the user's own.
            if (isset($this->userReadFailures[$key])) {
                throw $this->userReadFailures[$key];
            }
            if (!array_key_exists($key, $this->userRows)) {
                try {
                    $this->userRows[$key] = $stores->for($tenantId)->getAllForUser(FeatureFlagStores::MODULE, $userId);
                } catch (\Throwable $e) {
                    $this->userReadFailures[$key] = $e;
                    throw $e;
                }
            }
            $rows = $this->userRows[$key];
            if (self::isValue($rows[$flag] ?? null)) {
                return ['user', $rows[$flag]];
            }
        }
        if ($tenantId !== FeatureFlagStores::GLOBAL_TENANT) {
            $site = $stores->for($tenantId)->getAll(FeatureFlagStores::MODULE)[$flag] ?? null;
            if (self::isValue($site)) {
                return ['site', $site];
            }
        }

        $global = $stores->for(FeatureFlagStores::GLOBAL_TENANT)->getAll(FeatureFlagStores::MODULE)[$flag] ?? null;

        return self::isValue($global) ? ['global', $global] : ['declared', null];
    }

    /** A stored override this reads: on, off or a rollout. Anything else is skipped, not trusted. */
    private static function isValue(mixed $value): bool
    {
        return is_bool($value) || self::isRollout($value);
    }

    /** @phpstan-assert-if-true array{percent: int|string} $value */
    private static function isRollout(mixed $value): bool
    {
        // In range, as feature:set writes it: a stored "1e3" read as on for
        // everyone and a negative one as off. Out of range is skipped.
        return is_array($value) && isset($value['percent']) && is_numeric($value['percent'])
            && (float) $value['percent'] >= 0 && (float) $value['percent'] <= 100;
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
