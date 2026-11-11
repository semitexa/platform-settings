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
 * Only a lost first-write race (a primary-key violation) is read as "the row
 * is there now". Since a write joins the caller's transaction, a deadlock on
 * the INSERT has rolled that transaction back; swallowed, set() returned as if
 * saved and the rest of the unit ran outside any transaction (Fable review,
 * platform-settings#22).
 */
final class SettingsInsertFailureTest extends TestCase
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
    public function an_insert_that_fails_for_any_other_reason_reaches_the_caller(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        // No `id` column: the UPDATE runs (and matches nothing), the INSERT fails, not on a key.
        $orm->getAdapter()->execute(
            'CREATE TABLE platform_settings (tenant_id TEXT, user_id TEXT, module_key TEXT NOT NULL,
                setting_key TEXT NOT NULL, value TEXT NOT NULL, created_at TEXT, updated_at TEXT)',
        );
        $store = (new \ReflectionClass(SettingsStore::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(SettingsStore::class, 'orm'))->setValue($store, $orm);

        $this->expectException(\PDOException::class);
        $store->set('os', 'assistant_name', 'Ada');
    }
}
