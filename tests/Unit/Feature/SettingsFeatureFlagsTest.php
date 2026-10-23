<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Tests\Unit\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Feature\FeatureFlag;
use Semitexa\Core\Feature\FeatureFlagRegistry;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Platform\Settings\Application\Service\Feature\FeatureFlagWriter;
use Semitexa\Platform\Settings\Application\Service\Feature\PinnedTenantContextStore;
use Semitexa\Platform\Settings\Application\Service\Feature\SettingsFeatureFlags;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * Flags over the real SettingsStore on SQLite (ep-feature-flags, ff-2):
 * user > site > global > declaration, rollouts, and a failing store.
 */
final class SettingsFeatureFlagsTest extends TestCase
{
    private SettingsStore $store;
    private FeatureFlagRegistry $registry;

    protected function setUp(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute(
            'CREATE TABLE platform_settings (
                id TEXT PRIMARY KEY, tenant_id TEXT, user_id TEXT, module_key TEXT NOT NULL,
                setting_key TEXT NOT NULL, value TEXT NOT NULL, created_at TEXT, updated_at TEXT
            )',
        );
        // The request is for another site than every write below: reads must pin their tenants.
        $this->store = (new SettingsStore())->withTenantContextStore(new PinnedTenantContextStore('elsewhere'));
        (new \ReflectionProperty(SettingsStore::class, 'orm'))->setValue($this->store, $orm);

        $this->registry = FeatureFlagRegistry::of([
            new FeatureFlag('checkout.new-flow', false, '', null, self::class),
            new FeatureFlag('search.semantic', true, '', null, self::class),
        ]);
    }

    #[Test]
    public function the_most_specific_override_wins(): void
    {
        $writer = FeatureFlagWriter::of($this->store);
        $flags = SettingsFeatureFlags::of($this->registry, $this->store);

        self::assertFalse($flags->forSubject('acme', 'u1')->isEnabled('checkout.new-flow'), 'declared default');

        $writer->enable('checkout.new-flow');
        self::assertTrue($flags->forSubject('acme', 'u1')->isEnabled('checkout.new-flow'), 'global');
        self::assertTrue($flags->forSubject('globex', null)->isEnabled('checkout.new-flow'), 'global reaches every site');

        $writer->disable('checkout.new-flow', 'acme');
        self::assertFalse($flags->forSubject('acme', 'u1')->isEnabled('checkout.new-flow'), 'site beats global');
        self::assertTrue($flags->forSubject('globex', null)->isEnabled('checkout.new-flow'), 'another site keeps global');

        $writer->enable('checkout.new-flow', 'acme', 'u1');
        self::assertTrue($flags->forSubject('acme', 'u1')->isEnabled('checkout.new-flow'), 'user beats site');
        self::assertFalse($flags->forSubject('acme', 'u2')->isEnabled('checkout.new-flow'), 'another user has the site value');

        $writer->clear('checkout.new-flow', 'acme');
        self::assertTrue($flags->forSubject('acme', 'u2')->isEnabled('checkout.new-flow'), 'clearing the site hands back to global');

        $writer->disable('search.semantic');
        self::assertFalse($flags->forSubject(null, null)->isEnabled('search.semantic'), 'a declared-on flag can be turned off');
        self::assertFalse($flags->forSubject('acme', 'u1')->isEnabled('never.declared'), 'undeclared stays off even if stored');
    }

    #[Test]
    public function a_rollout_is_decided_per_user_and_holds_across_checks(): void
    {
        FeatureFlagWriter::of($this->store)->rollout('checkout.new-flow', 30, 'acme');
        $flags = SettingsFeatureFlags::of($this->registry, $this->store);

        $on = 0;
        for ($i = 0; $i < 300; $i++) {
            $first = $flags->forSubject('acme', 'user-' . $i)->isEnabled('checkout.new-flow');
            self::assertSame($first, $flags->forSubject('acme', 'user-' . $i)->isEnabled('checkout.new-flow'));
            $on += (int) $first;
        }
        self::assertEqualsWithDelta(90, $on, 35);
    }

    #[Test]
    public function a_write_reaches_the_next_request_on_a_store_that_was_cold(): void
    {
        // review: a cold store's clones each built their own snapshot cache, so
        // the next request read a stale copy and nothing was cached across requests.
        $before = SettingsFeatureFlags::of($this->registry, $this->store)->forSubject('acme', null);
        self::assertFalse($before->isEnabled('checkout.new-flow'));

        FeatureFlagWriter::of($this->store)->enable('checkout.new-flow', 'acme');

        self::assertTrue($before->isEnabled('checkout.new-flow'), 'the write cleared the snapshot every clone reads');
        $nextRequest = SettingsFeatureFlags::of($this->registry, $this->store)->forSubject('acme', null);
        self::assertTrue($nextRequest->isEnabled('checkout.new-flow'));
    }

    #[Test]
    public function a_guest_on_a_single_site_install_gets_the_rollout(): void
    {
        // review: with no site and no user the subject was null and a stored
        // rollout read as off, even at 100 %.
        $writer = FeatureFlagWriter::of($this->store);
        $flags = SettingsFeatureFlags::of($this->registry, $this->store);

        $writer->rollout('checkout.new-flow', 100);
        self::assertTrue($flags->forSubject(null, null)->isEnabled('checkout.new-flow'));
        $writer->rollout('checkout.new-flow', 0);
        self::assertFalse(SettingsFeatureFlags::of($this->registry, $this->store)->forSubject(null, null)->isEnabled('checkout.new-flow'));
    }

    #[Test]
    public function a_malformed_override_is_skipped_for_the_next_scope_out(): void
    {
        (clone $this->store)->withTenantContextStore(new PinnedTenantContextStore('acme'))->setForUser('feature_flags', 'checkout.new-flow', 'yes', 'u1');
        FeatureFlagWriter::of($this->store)->enable('checkout.new-flow', 'acme');

        $answer = SettingsFeatureFlags::of($this->registry, $this->store)->forSubject('acme', 'u1')->explain('checkout.new-flow');

        self::assertSame([true, 'site'], [$answer['enabled'], $answer['source']]);
    }

    #[Test]
    public function a_failing_store_answers_the_declared_default(): void
    {
        $broken = $this->createStub(SettingsStoreInterface::class);
        $broken->method('getAll')->willThrowException(new \RuntimeException('database is away'));
        $broken->method('getAllForUser')->willThrowException(new \RuntimeException('database is away'));

        $flags = SettingsFeatureFlags::of($this->registry, $broken);

        self::assertTrue($flags->forSubject('acme', 'u1')->isEnabled('search.semantic'));
        self::assertFalse($flags->forSubject('acme', 'u1')->isEnabled('checkout.new-flow'));
    }

    /** A page with ten flag checks and the store down logged ten warnings. */
    #[Test]
    public function a_down_store_is_said_once_a_minute_with_the_checks_it_cost(): void
    {
        $broken = $this->createStub(SettingsStoreInterface::class);
        $broken->method('getAll')->willThrowException(new \RuntimeException('database is away'));
        $lines = [];
        $logger = $this->createStub(\Semitexa\Core\Log\LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$lines): void {
            $lines[] = $context;
        });
        \Semitexa\Core\Log\StaticLoggerBridge::set($logger);
        $at = new \ReflectionProperty(SettingsFeatureFlags::class, 'fallbackWarnedAt');
        $unsaid = new \ReflectionProperty(SettingsFeatureFlags::class, 'fallbacksUnsaid');
        $at->setValue(null, 0.0);
        $unsaid->setValue(null, 0);
        try {
            $flags = SettingsFeatureFlags::of($this->registry, $broken)->forSubject('acme', null);
            for ($i = 0; $i < 10; $i++) {
                $flags->isEnabled('search.semantic');
            }
            self::assertCount(1, $lines, 'once, not per check');
            self::assertSame(1, $lines[0]['checks_since_last_warning']);

            $at->setValue(null, hrtime(true) / 1e9 - 61);
            $flags->isEnabled('search.semantic');
            self::assertCount(2, $lines);
            self::assertSame(10, $lines[1]['checks_since_last_warning'], 'the nine said nothing, then this one');
        } finally {
            \Semitexa\Core\Log\StaticLoggerBridge::reset();
            $at->setValue(null, 0.0);
            $unsaid->setValue(null, 0);
        }
    }

    #[Test]
    public function a_user_override_needs_its_site(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FeatureFlagWriter::of($this->store)->enable('checkout.new-flow', null, 'u1');
    }
}
