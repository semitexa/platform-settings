<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Service\Feature;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * Sets and clears flag overrides: for every site (no tenant), for one site,
 * or for one user of a site. A value is on/off or a rollout percentage.
 * Clearing a scope hands the decision back to the next one out.
 */
#[AsService]
final class FeatureFlagWriter
{
    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

    public static function of(SettingsStoreInterface $settings): self
    {
        $writer = new self();
        $writer->settings = $settings;

        return $writer;
    }

    public function enable(string $flag, ?string $tenantId = null, ?string $userId = null): void
    {
        $this->write($flag, true, $tenantId, $userId);
    }

    public function disable(string $flag, ?string $tenantId = null, ?string $userId = null): void
    {
        $this->write($flag, false, $tenantId, $userId);
    }

    public function rollout(string $flag, int $percent, ?string $tenantId = null, ?string $userId = null): void
    {
        $this->write($flag, ['percent' => max(0, min(100, $percent))], $tenantId, $userId);
    }

    public function clear(string $flag, ?string $tenantId = null, ?string $userId = null): void
    {
        $store = (new FeatureFlagStores($this->settings))->for(self::tenant($tenantId, $userId));
        $userId === null || $userId === ''
            ? $store->remove(FeatureFlagStores::MODULE, $flag)
            : $store->removeForUser(FeatureFlagStores::MODULE, $flag, $userId);
    }

    /** @param bool|array{percent: int} $value */
    private function write(string $flag, bool|array $value, ?string $tenantId, ?string $userId): void
    {
        $store = (new FeatureFlagStores($this->settings))->for(self::tenant($tenantId, $userId));
        $userId === null || $userId === ''
            ? $store->set(FeatureFlagStores::MODULE, $flag, $value)
            : $store->setForUser(FeatureFlagStores::MODULE, $flag, $value, $userId);
    }

    private static function tenant(?string $tenantId, ?string $userId): string
    {
        if ($tenantId === null || $tenantId === '') {
            if ($userId !== null && $userId !== '') {
                throw new \InvalidArgumentException('A user override needs the site (tenant) the user belongs to.');
            }

            return FeatureFlagStores::GLOBAL_TENANT;
        }

        return $tenantId;
    }
}
