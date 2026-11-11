<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Core\Support\CoroutineLocal;
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
                    try {
                        $orm->getTransactionManager()->run(static function () use ($store): void {
                            $store->set(self::MODULE, 'inserted', 'a');
                            throw new \RuntimeException('roll back');
                        });
                    } catch (\RuntimeException) {
                    }
                    $outcome['insert'] = $count();

                    // The UPDATE path: a row that exists, changed and rolled back.
                    $store->set(self::MODULE, 'updated', 'before');
                    try {
                        $orm->getTransactionManager()->run(static function () use ($store): void {
                            $store->set(self::MODULE, 'updated', 'after');
                            throw new \RuntimeException('roll back');
                        });
                    } catch (\RuntimeException) {
                    }
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
            // Hooks off outside a coroutine, every later Co\run() hooking again.
            \Swoole\Runtime::enableCoroutine(0);
            \Swoole\Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);
        }

        if ($failure !== null) {
            throw $failure;
        }
        self::assertSame(0, $outcome['insert'], 'the inserted setting rolled back with the transaction');
        self::assertSame('"before"', $outcome['update'], 'the updated setting rolled back with the transaction');
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
