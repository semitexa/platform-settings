<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;

/**
 * The store must work when it is built the way the CONTAINER builds it.
 *
 * The container does not run constructors for an #[AsService] class: it
 * instantiates and then injects properties. A dependency assigned in
 * __construct() is therefore left uninitialised on the only path that matters,
 * and PHP raises "Typed property ... must not be accessed before
 * initialization" at the first read.
 *
 * That bug shipped into this class and every unit test stayed green, because
 * `new SettingsStore()` runs the constructor. What caught it was the browser:
 * the OS shell and the CMS editor both answered 500. This test closes the gap
 * so the next one costs a PHPUnit run instead of an E2E run.
 */
final class SettingsStoreContainerShapeTest extends TestCase
{
    protected function setUp(): void
    {
        CoroutineLocal::resetCliStore();
    }

    protected function tearDown(): void
    {
        CoroutineLocal::resetCliStore();
    }

    #[Test]
    public function a_store_built_without_its_constructor_can_still_read_and_write(): void
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

        // Exactly what the container does: no constructor, then inject.
        $store = (new \ReflectionClass(SettingsStore::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(SettingsStore::class, 'orm'))->setValue($store, $orm);

        $store->set('os', 'assistant_name', 'Ada');

        self::assertSame('Ada', $store->get('os', 'assistant_name'));
        self::assertTrue($store->has('os', 'assistant_name'));
        self::assertNull($store->get('os', 'never_set'));
        self::assertSame(['assistant_name' => 'Ada'], $store->getAll('os'));
    }
}
