<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Tests\Unit\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Feature\FeatureFlag;
use Semitexa\Core\Feature\FeatureFlagRegistry;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Platform\Settings\Application\Console\Command\FeatureListCommand;
use Semitexa\Platform\Settings\Application\Service\Feature\FeatureFlagWriter;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * --user without --tenant read the user's rows under the every-site row,
 * found none of them, and listed the site or declared value as the answer.
 */
final class FeatureListCommandTest extends TestCase
{
    #[Test]
    public function a_user_is_listed_only_within_a_site(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute('CREATE TABLE platform_settings (id TEXT PRIMARY KEY, tenant_id TEXT, user_id TEXT, module_key TEXT NOT NULL, setting_key TEXT NOT NULL, value TEXT NOT NULL, created_at TEXT, updated_at TEXT)');
        $store = new SettingsStore();
        (new \ReflectionProperty(SettingsStore::class, 'orm'))->setValue($store, $orm);
        FeatureFlagWriter::of($store)->enable('checkout.new-flow', 'acme', 'u1');
        $command = new FeatureListCommand();
        (new \ReflectionProperty(FeatureListCommand::class, 'registry'))->setValue($command, FeatureFlagRegistry::of([new FeatureFlag('checkout.new-flow', false, 'New checkout', null, self::class)]));
        (new \ReflectionProperty(FeatureListCommand::class, 'settings'))->setValue($command, $store);

        $withoutSite = new CommandTester($command);
        $refused = $withoutSite->execute(['--user' => 'u1']);
        $withSite = new CommandTester($command);
        $listed = $withSite->execute(['--user' => 'u1', '--tenant' => 'acme', '--json' => true]);

        self::assertSame(Command::FAILURE, $refused);
        self::assertSame('--user needs --tenant: a user override is kept under the site (tenant) the user belongs to.', trim($withoutSite->getDisplay()));
        self::assertSame(Command::SUCCESS, $listed);
        self::assertSame(
            [['flag' => 'checkout.new-flow', 'enabled' => true, 'source' => 'user', 'value' => true, 'description' => 'New checkout']],
            json_decode($withSite->getDisplay(), true),
        );
    }
}
