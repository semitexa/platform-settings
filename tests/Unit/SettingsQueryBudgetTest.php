<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\Adapter\QueryRecorder;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;

/**
 * A budget, not a benchmark.
 *
 * The regression this guards against is invisible to every timing threshold the framework
 * has: one GET / issued 18 queries, 17 of them this table's same statement, at ~0.2 ms
 * each. Nothing was slow. There were simply seventeen of them, and `DB_SLOW_QUERY_MS` —
 * the only performance signal in the stack — cannot express "too many".
 *
 * Counts are also the half of performance that is deterministic. Wall-clock on this host
 * swings with whatever else is running (measured elsewhere: 20 compose projects, 1 GB
 * free), so a millisecond assertion would be flaky and get deleted. A query count is the
 * same number on a loaded laptop and on CI.
 */
final class SettingsQueryBudgetTest extends TestCase
{
    private SettingsStore $store;
    private BudgetTenantContextStore $ctx;

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

        $this->ctx = new BudgetTenantContextStore();
        $this->ctx->switchTo('acme');
        $this->store = (new SettingsStore())->withTenantContextStore($this->ctx);
        (new \ReflectionProperty(SettingsStore::class, 'orm'))->setValue($this->store, $orm);

        CoroutineLocal::resetCliStore();
    }

    protected function tearDown(): void
    {
        QueryRecorder::stop();
        QueryRecorder::drain();
        CoroutineLocal::resetCliStore();
    }

    /**
     * @return list<string>
     */
    private function reads(callable $body): array
    {
        QueryRecorder::start();
        QueryRecorder::drain();
        $body();
        $log = QueryRecorder::drain();
        QueryRecorder::stop();

        $reads = [];
        foreach ($log as $entry) {
            $sql = ' ' . strtoupper(preg_replace('/\s+/', ' ', trim($entry['sql'])) ?? '');
            if (str_starts_with($sql, ' SELECT') && str_contains($sql, 'PLATFORM_SETTINGS')) {
                $reads[] = $entry['sql'];
            }
        }

        return $reads;
    }

    #[Test]
    public function reading_one_setting_ten_times_costs_one_query(): void
    {
        $this->store->set('os', 'assistant_name', 'Ada');

        $reads = $this->reads(function (): void {
            for ($i = 0; $i < 10; ++$i) {
                self::assertSame('Ada', $this->store->get('os', 'assistant_name'));
            }
        });

        self::assertCount(1, $reads, '10 reads of one setting must cost exactly one round trip');
    }

    #[Test]
    public function an_absent_setting_is_not_looked_up_again_and_again(): void
    {
        // The shape most likely to sit in a loop: has() on a key that is not there.
        $reads = $this->reads(function (): void {
            for ($i = 0; $i < 10; ++$i) {
                self::assertFalse($this->store->has('os', 'never_set'));
            }
        });

        self::assertCount(1, $reads, 'a cached absence still has to be cached');
    }

    #[Test]
    public function a_write_is_visible_to_the_next_read_in_the_same_request(): void
    {
        // The failure mode a read cache invites. Worth more than the saved query.
        $this->store->set('os', 'assistant_name', 'Ada');
        self::assertSame('Ada', $this->store->get('os', 'assistant_name'));

        $this->store->set('os', 'assistant_name', 'Grace');
        self::assertSame('Grace', $this->store->get('os', 'assistant_name'));

        $this->store->remove('os', 'assistant_name');
        self::assertNull($this->store->get('os', 'assistant_name'));
        self::assertFalse($this->store->has('os', 'assistant_name'));
    }

    #[Test]
    public function the_cache_never_crosses_a_tenant_boundary(): void
    {
        // Tenant fan-out walks several tenants inside ONE coroutine, so this is the case a
        // cache key without the tenant would silently get wrong — and it holds the OS
        // persona settings, which makes it a cross-tenant disclosure, not a stale read.
        $this->ctx->switchTo('acme');
        $this->store->set('os', 'assistant_name', 'Ada');
        self::assertSame('Ada', $this->store->get('os', 'assistant_name'));

        $this->ctx->switchTo('globex');
        self::assertNull($this->store->get('os', 'assistant_name'), 'Globex must not inherit a cached Acme row');

        $this->store->set('os', 'assistant_name', 'Grace');
        self::assertSame('Grace', $this->store->get('os', 'assistant_name'));

        $this->ctx->switchTo('acme');
        self::assertSame('Ada', $this->store->get('os', 'assistant_name'), 'and Acme keeps its own');
    }

    #[Test]
    public function a_per_user_setting_does_not_answer_for_the_global_one(): void
    {
        $this->store->set('os', 'skin', 'dark');
        $this->store->setForUser('os', 'skin', 'light', 'u-1');

        self::assertSame('dark', $this->store->get('os', 'skin'));
        self::assertSame('light', $this->store->getForUser('os', 'skin', 'u-1'));
        self::assertNull($this->store->getForUser('os', 'skin', 'u-2'));
    }

    #[Test]
    public function compare_and_set_reads_the_row_and_not_the_cache(): void
    {
        // claim() is the concurrency primitive here. Answering it from a value this request
        // read earlier would make it decide about the past.
        $this->store->set('os', 'lock', 'free');
        self::assertSame('free', $this->store->get('os', 'lock'));

        self::assertTrue($this->store->claim('os', 'lock', 'free', 'taken'));
        self::assertSame('taken', $this->store->get('os', 'lock'), 'the winning claim is visible at once');
        self::assertFalse($this->store->claim('os', 'lock', 'free', 'again'));
    }
}

/**
 * Deliberately a second copy rather than reusing the one in SettingsTenantIsolationTest.
 * Reaching across test files for a helper makes a file that only passes when its
 * neighbour happens to be in the same run — this test failed exactly that way when run
 * alone, which is the sort of green that means nothing.
 */
final class BudgetTenantContextStore implements TenantContextStoreInterface
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
