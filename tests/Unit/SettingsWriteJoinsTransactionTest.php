<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;

/**
 * A setting written inside the caller's transaction rolls back with it
 * (tk-getadapter-writes-escape-transactions).
 *
 * The store's raw SQL went through getAdapter(), which under the Swoole pool
 * takes a connection of its own and autocommits: a caller that rolled back
 * still found the setting saved. In CLI the pool is one connection, so only a
 * coroutine pool on MySQL shows it; elsewhere this test skips.
 */
final class SettingsWriteJoinsTransactionTest extends TestCase
{
    /** Unique per run: the test deletes only its own rows in a database others use (review). */
    private static string $module = '';

    protected function setUp(): void
    {
        self::$module = 'tx-probe-' . bin2hex(random_bytes(6));
        CoroutineLocal::resetCliStore();
    }

    protected function tearDown(): void
    {
        CoroutineLocal::resetCliStore();
    }

    #[Test]
    public function a_setting_written_in_a_rolled_back_transaction_is_not_saved(): void
    {
        $this->requireMysql();
        $outcome = null;
        $failure = null;
        $hooks = \Swoole\Runtime::getHookFlags();
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        try {
            \Swoole\Coroutine\run(function () use (&$outcome, &$failure): void {
                $orm = new OrmManager();
                $count = static fn (): int => count($orm->getQueryAdapter()->execute(
                    'SELECT id FROM `platform_settings` WHERE module_key = :m',
                    ['m' => self::$module],
                )->rows);
                try {
                    $store = (new \ReflectionClass(SettingsStore::class))->newInstanceWithoutConstructor();
                    (new \ReflectionProperty(SettingsStore::class, 'orm'))->setValue($store, $orm);
                    // Only the deliberate rollback is caught, by identity: a write
                    // or a transaction start that fails fails the test (review).
                    $probe = new \RuntimeException('deliberate rollback');
                    $rolledBack = static function (\RuntimeException $e) use ($probe): void {
                        if ($e !== $probe) {
                            throw $e;
                        }
                    };
                    try {
                        $orm->getTransactionManager()->run(static function () use ($store, $probe): void {
                            $store->set(self::$module, 'inserted', 'a');
                            throw $probe;
                        });
                    } catch (\RuntimeException $e) {
                        $rolledBack($e);
                    }
                    $outcome['insert'] = $count();
                    $outcome['insert_read'] = $store->get(self::$module, 'inserted');

                    // The UPDATE path: a row that exists, changed and rolled back.
                    $store->set(self::$module, 'updated', 'before');
                    try {
                        $orm->getTransactionManager()->run(static function () use ($store, $probe, &$outcome): void {
                            $store->set(self::$module, 'updated', 'after');
                            // Read through the store inside: both caches now hold "after".
                            $outcome['inside'] = [$store->get(self::$module, 'updated'), $store->getAll(self::$module)['updated'] ?? null];
                            throw $probe;
                        });
                    } catch (\RuntimeException $e) {
                        $rolledBack($e);
                    }
                    $outcome['after'] = [$store->get(self::$module, 'updated'), $store->getAll(self::$module)['updated'] ?? null];
                    $outcome['update'] = $orm->getQueryAdapter()->execute(
                        'SELECT value FROM `platform_settings` WHERE module_key = :m AND setting_key = :k',
                        ['m' => self::$module, 'k' => 'updated'],
                    )->rows[0]['value'] ?? null;
                } catch (\Throwable $e) {
                    $failure = $e;
                } finally {
                    try {
                        // Only this run's rows: its own key, the tenant and the global scope it wrote in (review).
                        $orm->getQueryAdapter()->execute(
                            'DELETE FROM `platform_settings` WHERE module_key = :m AND tenant_id = :t AND user_id IS NULL',
                            ['m' => self::$module, 't' => TenantContextAccess::tenantIdOrDefault(null)],
                        );
                    } catch (\Throwable $e) {
                        $failure ??= $e;
                    } finally {
                        $orm->shutdown();
                    }
                }
            });
        } finally {
            // Hooks off outside a coroutine (switched on there they broke PDO in
            // later tests), and every later Co\run() hooking as it did before.
            \Swoole\Runtime::enableCoroutine(0);
            \Swoole\Coroutine::set(['hook_flags' => $hooks ?: SWOOLE_HOOK_ALL]);
        }

        if ($failure !== null) {
            throw $failure;
        }
        self::assertSame(0, $outcome['insert'], 'the inserted setting rolled back with the transaction');
        self::assertSame('"before"', $outcome['update'], 'the updated setting rolled back with the transaction');
        self::assertNull($outcome['insert_read'], 'a read after the rollback finds no inserted setting');
        self::assertSame(['after', 'after'], $outcome['inside'], 'inside, the store reads what it wrote');
        self::assertSame(['before', 'before'], $outcome['after'], 'after the rollback, neither cache serves the rolled-back value');
    }

    /** A rolled-back savepoint drops what its reads cached, and the outer commit keeps it dropped (review). */
    #[Test]
    public function a_rolled_back_savepoint_leaves_no_cached_value_behind(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute('CREATE TABLE platform_settings (id TEXT PRIMARY KEY, tenant_id TEXT, user_id TEXT,
            module_key TEXT NOT NULL, setting_key TEXT NOT NULL, value TEXT NOT NULL, created_at TEXT, updated_at TEXT)');
        $store = (new \ReflectionClass(SettingsStore::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(SettingsStore::class, 'orm'))->setValue($store, $orm);
        $store->set(self::$module, 'k', 'before');
        $probe = new \RuntimeException('deliberate rollback');
        $seen = [];

        $orm->getTransactionManager()->run(static function () use ($orm, $store, $probe, &$seen): void {
            try {
                $orm->getTransactionManager()->run(static function () use ($store, $probe, &$seen): void {
                    $store->set(self::$module, 'k', 'after');
                    $seen['inside'] = [$store->get(self::$module, 'k'), $store->getAll(self::$module)['k'] ?? null];
                    throw $probe;
                });
            } catch (\RuntimeException $e) {
                if ($e !== $probe) {
                    throw $e;
                }
            }
            $seen['outer'] = [$store->get(self::$module, 'k'), $store->getAll(self::$module)['k'] ?? null];
        });
        $seen['committed'] = [$store->get(self::$module, 'k'), $store->getAll(self::$module)['k'] ?? null];

        self::assertSame(['after', 'after'], $seen['inside']);
        self::assertSame(['before', 'before'], $seen['outer'], 'the savepoint rollback dropped both caches');
        self::assertSame(['before', 'before'], $seen['committed']);
    }

    /**
     * A commit that threw may have gone through or not; a value read and cached
     * during it must not outlive it (tk-failed-commit-keeps-cached-write). The
     * cache drop ran on a rollback only, so a failed COMMIT left the uncommitted
     * value cached. A deferred foreign key makes this COMMIT fail.
     */
    #[Test]
    public function a_failed_commit_leaves_no_cached_value_behind(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $db = $orm->getAdapter();
        $db->execute('CREATE TABLE platform_settings (id TEXT PRIMARY KEY, tenant_id TEXT, user_id TEXT,
            module_key TEXT NOT NULL, setting_key TEXT NOT NULL, value TEXT NOT NULL, created_at TEXT, updated_at TEXT)');
        $db->execute('PRAGMA foreign_keys = ON');
        $db->execute('CREATE TABLE fk_parent (id INTEGER PRIMARY KEY)');
        $db->execute('CREATE TABLE fk_child (parent_id INTEGER REFERENCES fk_parent(id) DEFERRABLE INITIALLY DEFERRED)');
        $store = (new \ReflectionClass(SettingsStore::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(SettingsStore::class, 'orm'))->setValue($store, $orm);
        $store->set(self::$module, 'k', 'before');
        $inside = null;

        try {
            $orm->getTransactionManager()->run(static function ($tx) use ($store, &$inside): void {
                $store->set(self::$module, 'k', 'after');
                $inside = [$store->get(self::$module, 'k'), $store->getAll(self::$module)['k'] ?? null];
                $tx->execute('INSERT INTO fk_child (parent_id) VALUES (404)');
            });
            self::fail('the deferred foreign key must fail the COMMIT');
        } catch (\PDOException) {
        }

        self::assertSame(['after', 'after'], $inside, 'cached during the transaction');
        self::assertSame(['before', 'before'], [$store->get(self::$module, 'k'), $store->getAll(self::$module)['k'] ?? null], 'neither cache serves what did not commit');
    }

    private function requireMysql(): void
    {
        $orm = new OrmManager();
        try {
            $cliHost = Environment::getEnvValue('DB_CLI_HOST');
            $host = !file_exists('/.dockerenv') && $cliHost !== null && $cliHost !== '' ? $cliHost : Environment::getEnvValue('DB_HOST');
            if ($orm->getDriver() !== 'mysql' || $host === null || $host === '') {
                self::markTestSkipped('needs the MySQL pool: the escape shows only on a second connection');
            }
            // Configured and not answering, or no table: a failure, not a skip.
            $orm->getAdapter()->execute('SELECT 1 FROM `platform_settings` LIMIT 1');
        } finally {
            $orm->shutdown();
        }
    }
}
