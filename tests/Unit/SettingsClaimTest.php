<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;

/**
 * The cross-worker compare-and-set primitive. Every Swoole worker sees the
 * same shared setting, so a plain get-then-set lets all of them act on it;
 * claim() is the atomic single-winner transition the weaver's cursor advance
 * relies on. Exercised through the real guarded UPDATE against in-memory
 * SQLite.
 */
final class SettingsClaimTest extends TestCase
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

    #[Test]
    public function claiming_an_absent_setting_seeds_it_and_wins(): void
    {
        self::assertTrue($this->store->claim('os', 'weave.cursor', '', 'batch-1'));
        self::assertSame('batch-1', $this->store->get('os', 'weave.cursor'));
    }

    #[Test]
    public function only_one_concurrent_claim_with_the_same_expected_wins(): void
    {
        $this->store->set('os', 'weave.cursor', 'batch-1');

        // Two workers both read 'batch-1' and race to advance it to 'batch-2'.
        self::assertTrue($this->store->claim('os', 'weave.cursor', 'batch-1', 'batch-2'), 'first worker wins');
        self::assertFalse($this->store->claim('os', 'weave.cursor', 'batch-1', 'batch-2'), 'second worker loses');
        self::assertFalse($this->store->claim('os', 'weave.cursor', 'batch-1', 'batch-2'), 'and every worker after');

        self::assertSame('batch-2', $this->store->get('os', 'weave.cursor'));
    }

    #[Test]
    public function a_stale_expected_value_never_wins(): void
    {
        $this->store->set('os', 'weave.cursor', 'batch-5');

        // A worker that read an older cursor must not clobber the newer one.
        self::assertFalse($this->store->claim('os', 'weave.cursor', 'batch-1', 'batch-2'));
        self::assertSame('batch-5', $this->store->get('os', 'weave.cursor'));
    }

    #[Test]
    public function a_rolled_back_cursor_can_be_reclaimed(): void
    {
        $this->store->set('os', 'weave.cursor', 'C0');
        self::assertTrue($this->store->claim('os', 'weave.cursor', 'C0', 'C1'));

        // Simulate the weaver's LLM-failure rollback, then a later retry.
        $this->store->set('os', 'weave.cursor', 'C0');
        self::assertTrue($this->store->claim('os', 'weave.cursor', 'C0', 'C1'), 'the batch retries after rollback');
    }
}
