<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Service\Feature;

use Semitexa\Platform\Settings\Application\Service\SettingsStore;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * The settings store for one tenant. Flags are read and written in fixed
 * tenants — the site's row and the global `default` row — whatever site the
 * request is for, so the store is cloned with a pinned tenant. The clones
 * share the worker's tenant-keyed snapshots: a write through one clears the
 * entry the others read. A store that is not SettingsStore (a test fake)
 * cannot be pinned and answers for its own tenant.
 */
final class FeatureFlagStores
{
    public const MODULE = 'feature_flags';
    public const GLOBAL_TENANT = 'default';

    /** @var array<string, SettingsStoreInterface> */
    private array $pinned = [];

    public function __construct(private readonly SettingsStoreInterface $store) {}

    public function for(string $tenantId): SettingsStoreInterface
    {
        if (!$this->store instanceof SettingsStore) {
            return $this->store;
        }

        if ($this->pinned === []) {
            // A clone shares the store's snapshot cache only if the store has
            // built it: a cold store's clones each built their own, so nothing
            // was cached across requests and a write cleared another copy. One
            // getAll() builds it (and is itself served from it next time).
            $this->store->getAll(self::MODULE);
        }

        return $this->pinned[$tenantId] ??= (clone $this->store)->withTenantContextStore(new PinnedTenantContextStore($tenantId));
    }
}
