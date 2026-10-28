<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Tests\Unit\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Feature\FeatureFlag;
use Semitexa\Core\Feature\FeatureFlagRegistry;
use Semitexa\Core\Tenant\TenantFanoutInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Platform\Settings\Application\Console\Command\FeatureSetCommand;
use Semitexa\Platform\Settings\Application\Service\Feature\FeatureFlagWriter;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;
use Symfony\Component\Console\Tester\CommandTester;

/** A mistyped site was stored, and the override applied to nobody. */
final class FeatureSetCommandScopeTest extends TestCase
{
    #[Test]
    public function an_unknown_site_is_refused_unless_forced(): void
    {
        $command = $this->command(['acme', 'globex'], $orm);

        self::assertSame(0, $this->execute($command, ['--tenant' => 'acme', '--user' => 'u1']));
        self::assertSame([['acme', 'u1']], self::rows($orm), 'the user row is stored');
        self::assertSame(1, $this->execute($command, ['--tenant' => 'acmee'], $out));
        self::assertStringContainsString("No site 'acmee' (sites: acme, globex)", $out);
        self::assertSame([['acme', 'u1']], self::rows($orm), 'nothing stored for the refused site');
        self::assertSame(0, $this->execute($command, ['--tenant' => 'acmee', '--force' => true]));
        self::assertSame([['acme', 'u1'], ['acmee', null]], self::rows($orm), 'forced, it is stored');
        // The every-site row: not one of a tenancy install's sites, but always there.
        self::assertSame(0, $this->execute($command, ['--tenant' => 'default']));
    }

    /** REGRESSION (review): --tenant=default wrote the every-site row and said "for site default". */
    #[Test]
    public function the_default_site_is_reported_as_every_site(): void
    {
        $command = $this->command(['acme']);

        self::assertSame(0, $this->execute($command, ['--tenant' => 'default'], $out));
        self::assertSame('checkout.new-flow: on for every site. Running workers see it within 2 s.', trim($out));
        self::assertSame(0, $this->execute($command, [], $out));
        self::assertSame('checkout.new-flow: on for every site. Running workers see it within 2 s.', trim($out));
        self::assertSame(0, $this->execute($command, ['--tenant' => 'acme'], $out));
        self::assertSame('checkout.new-flow: on for site acme. Running workers see it within 2 s.', trim($out));
        self::assertSame(0, $this->execute($command, ['--tenant' => 'acme', '--user' => 'u1'], $out));
        self::assertSame('checkout.new-flow: on for user u1 of acme. Running workers see it within 2 s.', trim($out));
    }

    /**
     * @param array<string, mixed> $options
     * @param-out string $output
     */
    private function execute(FeatureSetCommand $command, array $options, ?string &$output = null): int
    {
        $tester = new CommandTester($command);
        $code = $tester->execute(['flag' => 'checkout.new-flow', 'value' => 'on'] + $options);
        $output = $tester->getDisplay();

        return $code;
    }

    /** @return list<array{0: mixed, 1: mixed}> tenant and user of every stored row */
    private static function rows(OrmManager $orm): array
    {
        return array_map(
            static fn (array $row): array => [$row['tenant_id'], $row['user_id']],
            array_values($orm->getAdapter()->execute('SELECT tenant_id, user_id FROM platform_settings ORDER BY tenant_id, user_id')->fetchAll()),
        );
    }

    /**
     * @param list<string> $sites
     * @param-out OrmManager $orm
     */
    private function command(array $sites, ?OrmManager &$orm = null): FeatureSetCommand
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute('CREATE TABLE platform_settings (id TEXT PRIMARY KEY, tenant_id TEXT, user_id TEXT, module_key TEXT NOT NULL, setting_key TEXT NOT NULL, value TEXT NOT NULL, created_at TEXT, updated_at TEXT)');
        $store = new SettingsStore();
        (new \ReflectionProperty(SettingsStore::class, 'orm'))->setValue($store, $orm);
        $fanout = new class ($sites) implements TenantFanoutInterface {
            /** @param list<string> $sites */
            public function __construct(private array $sites) {}
            public function eachTenant(\Closure $work): void
            {
                foreach ($this->sites as $site) {
                    $work($site);
                }
            }
        };
        $command = new FeatureSetCommand();
        foreach ([
            'registry' => FeatureFlagRegistry::of([new FeatureFlag('checkout.new-flow', false, '', null, self::class)]),
            'writer' => FeatureFlagWriter::of($store),
            'tenants' => $fanout,
        ] as $property => $value) {
            (new \ReflectionProperty(FeatureSetCommand::class, $property))->setValue($command, $value);
        }

        return $command;
    }
}
