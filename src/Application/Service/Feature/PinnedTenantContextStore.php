<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Service\Feature;

use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;

/**
 * A tenant context store that always answers one tenant: read a site's
 * settings (or the global `default` row) whatever site the request is for.
 */
final class PinnedTenantContextStore implements TenantContextStoreInterface
{
    private readonly TenantContextInterface $context;

    public function __construct(string $tenantId)
    {
        $this->context = new class ($tenantId) implements TenantContextInterface {
            public function __construct(private readonly string $tenantId) {}

            public function getTenantId(): string
            {
                return $this->tenantId;
            }

            public function isDefault(): bool
            {
                return $this->tenantId === 'default';
            }

            public function getLayer(TenantLayerInterface $layer): ?TenantLayerValueInterface
            {
                return null;
            }

            public function hasLayer(TenantLayerInterface $layer): bool
            {
                return false;
            }
        };
    }

    public function get(): TenantContextInterface
    {
        return $this->context;
    }

    public function tryGet(): ?TenantContextInterface
    {
        return $this->context;
    }

    public function set(TenantContextInterface $context): void
    {
    }

    public function clear(): void
    {
    }
}
