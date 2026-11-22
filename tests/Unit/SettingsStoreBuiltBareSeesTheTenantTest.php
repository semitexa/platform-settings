<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Tenant\DefaultTenantContextStore;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;

/**
 * Skills and services build this store with `new SettingsStore()`: no tenant
 * store is injected. It answered 'default' then without asking anything, so
 * every tenant read and wrote one tenant's settings. It must ask core's
 * store, which reads the slot the active tenant store writes.
 */
final class SettingsStoreBuiltBareSeesTheTenantTest extends TestCase
{
    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->orm->getAdapter()->execute(
            'CREATE TABLE platform_settings (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                user_id TEXT,
                module_key TEXT NOT NULL,
                setting_key TEXT NOT NULL,
                value TEXT NOT NULL,
                created_at TEXT,
                updated_at TEXT, updated_by TEXT
            )',
        );
    }

    #[Test]
    public function outside_a_coroutine_two_tenants_keep_their_own_value_under_one_key(): void
    {
        // The slot is process-wide out here: whatever another fixture left in
        // it is put back, not wiped.
        $previous = CoroutineLocal::get(DefaultTenantContextStore::CONTEXT_KEY);
        try {
            self::activate('tenant-acme');
            $this->bareStore()->set('os', 'assistant_name', 'Ada');

            self::activate('tenant-globex');
            self::assertNull($this->bareStore()->get('os', 'assistant_name'), 'Globex must not read Acme\'s value.');
            $this->bareStore()->set('os', 'assistant_name', 'Grace');

            self::activate('tenant-acme');
            self::assertSame('Ada', $this->bareStore()->get('os', 'assistant_name'));
        } finally {
            if ($previous === null) {
                CoroutineLocal::remove(DefaultTenantContextStore::CONTEXT_KEY);
            } else {
                CoroutineLocal::set(DefaultTenantContextStore::CONTEXT_KEY, $previous);
            }
        }

        self::assertSame(
            ['tenant-acme' => 'Ada', 'tenant-globex' => 'Grace'],
            $this->valuesByTenant(),
        );
    }

    #[Test]
    public function two_tenants_in_two_coroutines_each_write_under_their_own_id(): void
    {
        $hooks = \Swoole\Runtime::getHookFlags();
        try {
            \Swoole\Coroutine\run(function (): void {
                foreach (['tenant-acme' => 'Ada', 'tenant-globex' => 'Grace'] as $tenantId => $name) {
                    \Swoole\Coroutine::create(function () use ($tenantId, $name): void {
                        // What semitexa/tenancy's store does on set().
                        self::activate($tenantId);
                        // Yield, so the other tenant's coroutine runs in between.
                        \Swoole\Coroutine::sleep(0.005);
                        $this->bareStore()->set('os', 'assistant_name', $name);
                    });
                }
            });
        } finally {
            \Swoole\Coroutine::set(['hook_flags' => $hooks ?: SWOOLE_HOOK_ALL]);
        }

        self::assertSame(
            ['tenant-acme' => 'Ada', 'tenant-globex' => 'Grace'],
            $this->valuesByTenant(),
        );
    }

    private function bareStore(): SettingsStore
    {
        $store = new SettingsStore();
        (new \ReflectionProperty(SettingsStore::class, 'orm'))->setValue($store, $this->orm);

        return $store;
    }

    /** @return array<string, mixed> */
    private function valuesByTenant(): array
    {
        $rows = $this->orm->getAdapter()
            ->execute('SELECT tenant_id, value FROM platform_settings ORDER BY tenant_id')
            ->fetchAll();

        $byTenant = [];
        foreach ($rows as $row) {
            self::assertIsString($row['tenant_id']);
            self::assertIsString($row['value']);
            $byTenant[$row['tenant_id']] = json_decode($row['value'], true);
        }

        return $byTenant;
    }

    private static function activate(string $tenantId): void
    {
        CoroutineLocal::set(DefaultTenantContextStore::CONTEXT_KEY, new class ($tenantId) implements TenantContextInterface {
            public function __construct(private readonly string $id) {}

            public function getTenantId(): string
            {
                return $this->id;
            }

            public function getLayer(TenantLayerInterface $layer): ?TenantLayerValueInterface
            {
                return null;
            }

            public function hasLayer(TenantLayerInterface $layer): bool
            {
                return false;
            }
        });
    }
}
