<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Platform\Settings\Application\Service\SettingsModuleSnapshots;

final class SettingsModuleSnapshotsTest extends TestCase
{
    private float $now = 1000.0;

    private function snapshots(): SettingsModuleSnapshots
    {
        return new SettingsModuleSnapshots(fn (): float => $this->now);
    }

    #[Test]
    public function a_snapshot_is_served_until_its_ttl_runs_out(): void
    {
        $snapshots = $this->snapshots();
        $loads = 0;
        $load = function () use (&$loads): array {
            return ['n' => ++$loads];
        };

        self::assertSame(['n' => 1], $snapshots->get('acme', 'locale', $load));
        $this->now += SettingsModuleSnapshots::TTL_SECONDS - 0.01;
        self::assertSame(['n' => 1], $snapshots->get('acme', 'locale', $load));
        $this->now += 0.02;
        self::assertSame(['n' => 2], $snapshots->get('acme', 'locale', $load));
    }

    #[Test]
    public function tenants_and_modules_never_share_a_snapshot(): void
    {
        $snapshots = $this->snapshots();

        $snapshots->get('acme', 'locale', static fn (): array => ['default' => 'uk']);

        self::assertSame(['default' => 'en'], $snapshots->get('globex', 'locale', static fn (): array => ['default' => 'en']));
        self::assertSame([], $snapshots->get('acme', 'site_head', static fn (): array => []));
        self::assertSame(['default' => 'uk'], $snapshots->get('acme', 'locale', static fn (): array => ['default' => 'xx']));
    }

    #[Test]
    public function forget_drops_the_snapshot_at_once(): void
    {
        $snapshots = $this->snapshots();
        $snapshots->get('acme', 'locale', static fn (): array => ['default' => 'uk']);

        $snapshots->forget('acme', 'locale');

        self::assertSame(['default' => 'de'], $snapshots->get('acme', 'locale', static fn (): array => ['default' => 'de']));
    }

    #[Test]
    public function a_load_that_raced_a_forget_is_not_stored(): void
    {
        $snapshots = $this->snapshots();

        // The load yields (database I/O) and a write in another coroutine lands meanwhile.
        $stale = $snapshots->get('acme', 'locale', static function () use ($snapshots): array {
            $snapshots->forget('acme', 'locale');

            return ['default' => 'old'];
        });

        self::assertSame(['default' => 'old'], $stale, 'the racing caller still gets what it read');
        self::assertSame(['default' => 'new'], $snapshots->get('acme', 'locale', static fn (): array => ['default' => 'new']));
    }

    #[Test]
    public function a_failed_load_is_rethrown_without_retrying_until_its_window_ends(): void
    {
        $snapshots = $this->snapshots();
        $attempts = 0;
        $failing = function () use (&$attempts): array {
            ++$attempts;
            throw new \PDOException('SQLSTATE[HY000] [2002] Connection refused');
        };

        for ($i = 0; $i < 3; ++$i) {
            try {
                $snapshots->get('acme', 'locale', $failing);
                self::fail('expected the load failure to surface');
            } catch (\PDOException $e) {
                self::assertStringContainsString('Connection refused', $e->getMessage());
            }
        }
        self::assertSame(1, $attempts, 'an outage costs one connect attempt per window, not one per request');

        $this->now += SettingsModuleSnapshots::FAILURE_TTL_SECONDS + 0.01;
        self::assertSame(['default' => 'uk'], $snapshots->get('acme', 'locale', static fn (): array => ['default' => 'uk']));
    }
}
