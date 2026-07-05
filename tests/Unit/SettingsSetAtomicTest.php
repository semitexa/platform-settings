<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

/**
 * set() must not create DUPLICATE rows. The old find-then-insert let two
 * concurrent writers of the same NEW scope both see "no row" and both INSERT —
 * and the uniq_platform_settings_scope index can't stop them because its
 * NULL tenant_id/user_id columns are treated as distinct. The rewrite writes
 * one row per scope via UPDATE-first + a deterministic-id INSERT (concurrent
 * first-writers collide on the primary key).
 */
final class SettingsSetAtomicTest extends TestCase
{
    private SettingsStore $store;
    private DatabaseAdapterInterface $db;

    protected function setUp(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->db = $orm->getAdapter();
        $this->db->execute(
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

        $this->store = new SettingsStore();
        (new \ReflectionProperty(SettingsStore::class, 'orm'))->setValue($this->store, $orm);
    }

    private function rowCount(string $module, string $key): int
    {
        return (int) $this->db->execute(
            'SELECT COUNT(*) AS c FROM platform_settings WHERE module_key = :m AND setting_key = :k',
            ['m' => $module, 'k' => $key],
        )->rows[0]['c'];
    }

    #[Test]
    public function repeated_set_of_a_new_scope_keeps_one_row(): void
    {
        $this->store->set('os', 'weave.cursor', 'a');
        $this->store->set('os', 'weave.cursor', 'b');
        $this->store->set('os', 'weave.cursor', 'c');

        self::assertSame(1, $this->rowCount('os', 'weave.cursor'));
        self::assertSame('c', $this->store->get('os', 'weave.cursor'), 'last write wins');
    }

    #[Test]
    public function a_new_scope_is_written_under_a_deterministic_id(): void
    {
        // The load-bearing mechanism: the same scope always hashes to the same
        // primary-key id, so two concurrent first-writers collide on the PK
        // instead of inserting duplicate rows (the old code used a random UUID,
        // which never collides). Prove determinism without reaching the private
        // helper: the SAME scope written on two independent fresh databases
        // gets the SAME row id.
        $this->store->set('os', 'weave.cursor', 'v1');
        $idA = $this->db->execute(
            'SELECT id FROM platform_settings WHERE module_key = :m AND setting_key = :k',
            ['m' => 'os', 'k' => 'weave.cursor'],
        )->rows[0]['id'];

        $otherOrm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $otherDb = $otherOrm->getAdapter();
        $otherDb->execute(
            'CREATE TABLE platform_settings (id TEXT PRIMARY KEY, tenant_id TEXT, user_id TEXT,
                module_key TEXT NOT NULL, setting_key TEXT NOT NULL, value TEXT NOT NULL,
                created_at TEXT, updated_at TEXT)',
        );
        $otherStore = new SettingsStore();
        (new \ReflectionProperty(SettingsStore::class, 'orm'))->setValue($otherStore, $otherOrm);
        $otherStore->set('os', 'weave.cursor', 'different-value');
        $idB = $otherDb->execute(
            'SELECT id FROM platform_settings WHERE module_key = :m AND setting_key = :k',
            ['m' => 'os', 'k' => 'weave.cursor'],
        )->rows[0]['id'];

        self::assertSame($idA, $idB, 'the same scope must derive the same primary-key id');
        self::assertNotSame('', (string) $idA);
    }

    #[Test]
    public function concurrent_first_writers_produce_exactly_one_row(): void
    {
        // End-state invariant. (The test harness cannot force the two writers to
        // interleave at the find/insert boundary — so this holds under the old
        // code too — but it pins the guarantee callers depend on.)
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function (): void {
            $wg = new WaitGroup();
            for ($i = 0; $i < 6; $i++) {
                $wg->add();
                Coroutine::create(function () use ($wg, $i): void {
                    $this->store->set('os', 'contended', 'writer-' . $i);
                    $wg->done();
                });
            }
            $wg->wait();
        });

        self::assertSame(1, $this->rowCount('os', 'contended'), 'one row per scope');
    }

    #[Test]
    public function global_and_user_scopes_are_distinct_rows(): void
    {
        $this->store->set('os', 'theme', 'dark');            // global (user_id NULL)
        $this->store->setForUser('os', 'theme', 'light', 'user-1');

        self::assertSame(2, $this->rowCount('os', 'theme'), 'global and per-user are separate scopes');
        self::assertSame('dark', $this->store->get('os', 'theme'));
        self::assertSame('light', $this->store->getForUser('os', 'theme', 'user-1'));
    }
}
