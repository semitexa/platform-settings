<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;

/**
 * platform_settings is #[TenantScoped]; the store threads the ambient tenant
 * through every path (query-builder reads AND the raw-SQL upsert/CAS/update).
 * These pins prove one tenant can never read, overwrite, CAS, or enumerate
 * another tenant's settings — even on an IDENTICAL (module, key) scope, which
 * is exactly the assistant-name / user-name / timezone / skin state that feeds
 * the OS LLM persona prompt.
 */
final class SettingsTenantIsolationTest extends TestCase
{
    private SettingsStore $store;
    private SwitchableTenantContextStore $ctx;

    protected function setUp(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute(
            'CREATE TABLE platform_settings (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                user_id TEXT,
                module_key TEXT NOT NULL,
                setting_key TEXT NOT NULL,
                value TEXT NOT NULL,
                created_at TEXT,
                updated_at TEXT
            )',
        );

        $this->ctx = new SwitchableTenantContextStore();
        $this->store = (new SettingsStore())->withTenantContextStore($this->ctx);
        (new \ReflectionProperty(SettingsStore::class, 'orm'))->setValue($this->store, $orm);
    }

    #[Test]
    public function the_same_scope_key_holds_independent_values_per_tenant(): void
    {
        $this->ctx->switchTo('acme');
        $this->store->set('os', 'assistant_name', 'Ada');

        $this->ctx->switchTo('globex');
        self::assertNull($this->store->get('os', 'assistant_name'), 'Globex must not read Acme\'s value.');

        $this->store->set('os', 'assistant_name', 'Grace');
        self::assertSame('Grace', $this->store->get('os', 'assistant_name'));

        // Acme is untouched by Globex's write on the identical scope key.
        $this->ctx->switchTo('acme');
        self::assertSame('Ada', $this->store->get('os', 'assistant_name'));
    }

    #[Test]
    public function get_all_never_bleeds_another_tenants_settings(): void
    {
        $this->ctx->switchTo('acme');
        $this->store->set('os', 'a', 1);
        $this->store->set('os', 'b', 2);

        $this->ctx->switchTo('globex');
        $this->store->set('os', 'c', 3);

        self::assertSame(['c' => 3], $this->store->getAll('os'), 'Globex enumerates only its own scope.');

        $this->ctx->switchTo('acme');
        self::assertSame(['a' => 1, 'b' => 2], $this->store->getAll('os'));
    }

    #[Test]
    public function claim_cannot_win_against_another_tenants_value(): void
    {
        $this->ctx->switchTo('acme');
        $this->store->set('os', 'weave.cursor', 'acme-batch-1');

        // Globex has no such row → claim seeds ITS OWN and wins.
        $this->ctx->switchTo('globex');
        self::assertTrue($this->store->claim('os', 'weave.cursor', '', 'globex-batch-1'));

        // Globex's CAS from its own value succeeds and does not touch Acme.
        self::assertTrue($this->store->claim('os', 'weave.cursor', 'globex-batch-1', 'globex-batch-2'));
        self::assertSame('globex-batch-2', $this->store->get('os', 'weave.cursor'));

        $this->ctx->switchTo('acme');
        self::assertSame('acme-batch-1', $this->store->get('os', 'weave.cursor'), 'Acme cursor untouched.');
    }

    #[Test]
    public function remove_only_affects_the_current_tenant(): void
    {
        $this->ctx->switchTo('acme');
        $this->store->set('os', 'k', 'acme-val');
        $this->ctx->switchTo('globex');
        $this->store->set('os', 'k', 'globex-val');

        $this->store->remove('os', 'k');
        self::assertFalse($this->store->has('os', 'k'));

        $this->ctx->switchTo('acme');
        self::assertTrue($this->store->has('os', 'k'), 'Acme setting survives Globex remove().');
        self::assertSame('acme-val', $this->store->get('os', 'k'));
    }
}

final class SwitchableTenantContextStore implements TenantContextStoreInterface
{
    private ?TenantContextInterface $context = null;

    public function switchTo(string $tenantId): void
    {
        $this->context = new class ($tenantId) implements TenantContextInterface {
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
        };
    }

    public function get(): TenantContextInterface
    {
        return $this->context ?? throw new \LogicException('no context');
    }

    public function tryGet(): ?TenantContextInterface
    {
        return $this->context;
    }

    public function set(TenantContextInterface $context): void
    {
        $this->context = $context;
    }

    public function clear(): void
    {
        $this->context = null;
    }
}
