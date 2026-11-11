<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Core\Support\CoroutineLocal;
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
    private const MODULE = 'tx-probe';

    protected function setUp(): void
    {
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
                    ['m' => self::MODULE],
                )->rows);
                try {
                    $orm->getQueryAdapter()->execute('DELETE FROM `platform_settings` WHERE module_key = :m', ['m' => self::MODULE]);
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
                            $store->set(self::MODULE, 'inserted', 'a');
                            throw $probe;
                        });
                    } catch (\RuntimeException $e) {
                        $rolledBack($e);
                    }
                    $outcome['insert'] = $count();
                    $outcome['insert_read'] = $store->get(self::MODULE, 'inserted');

                    // The UPDATE path: a row that exists, changed and rolled back.
                    $store->set(self::MODULE, 'updated', 'before');
                    try {
                        $orm->getTransactionManager()->run(static function () use ($store, $probe, &$outcome): void {
                            $store->set(self::MODULE, 'updated', 'after');
                            // Read through the store inside: both caches now hold "after".
                            $outcome['inside'] = [$store->get(self::MODULE, 'updated'), $store->getAll(self::MODULE)['updated'] ?? null];
                            throw $probe;
                        });
                    } catch (\RuntimeException $e) {
                        $rolledBack($e);
                    }
                    $outcome['after'] = [$store->get(self::MODULE, 'updated'), $store->getAll(self::MODULE)['updated'] ?? null];
                    $outcome['update'] = $orm->getQueryAdapter()->execute(
                        'SELECT value FROM `platform_settings` WHERE module_key = :m AND setting_key = :k',
                        ['m' => self::MODULE, 'k' => 'updated'],
                    )->rows[0]['value'] ?? null;
                } catch (\Throwable $e) {
                    $failure = $e;
                } finally {
                    try {
                        $orm->getQueryAdapter()->execute('DELETE FROM `platform_settings` WHERE module_key = :m', ['m' => self::MODULE]);
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
        $store->set(self::MODULE, 'k', 'before');
        $probe = new \RuntimeException('deliberate rollback');
        $seen = [];

        $orm->getTransactionManager()->run(static function () use ($orm, $store, $probe, &$seen): void {
            try {
                $orm->getTransactionManager()->run(static function () use ($store, $probe, &$seen): void {
                    $store->set(self::MODULE, 'k', 'after');
                    $seen['inside'] = [$store->get(self::MODULE, 'k'), $store->getAll(self::MODULE)['k'] ?? null];
                    throw $probe;
                });
            } catch (\RuntimeException $e) {
                if ($e !== $probe) {
                    throw $e;
                }
            }
            $seen['outer'] = [$store->get(self::MODULE, 'k'), $store->getAll(self::MODULE)['k'] ?? null];
        });
        $seen['committed'] = [$store->get(self::MODULE, 'k'), $store->getAll(self::MODULE)['k'] ?? null];

        self::assertSame(['after', 'after'], $seen['inside']);
        self::assertSame(['before', 'before'], $seen['outer'], 'the savepoint rollback dropped both caches');
        self::assertSame(['before', 'before'], $seen['committed']);
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
