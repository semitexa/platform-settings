<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Tests\Unit\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Auth\Event\UserDeleted;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;
use Semitexa\Platform\Settings\Application\Service\UserDeletedSettingsListener;

/** A deleted user's own settings and flag overrides stayed, for whoever got the id next. */
final class UserDeletedSettingsTest extends TestCase
{
    #[Test]
    public function a_deleted_users_settings_go_on_every_site_and_nobody_elses_do(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute('CREATE TABLE platform_settings (id TEXT PRIMARY KEY, tenant_id TEXT, user_id TEXT, module_key TEXT NOT NULL, setting_key TEXT NOT NULL, value TEXT NOT NULL, created_at TEXT, updated_at TEXT)');
        $orm->getAdapter()->execute("INSERT INTO platform_settings VALUES ('1', 'acme', 'u1', 'feature_flags', 'x', 'true', NULL, NULL), ('2', 'globex', 'u1', 'ui', 'theme', '\"dark\"', NULL, NULL), ('3', 'acme', 'u2', 'feature_flags', 'x', 'true', NULL, NULL), ('4', 'acme', NULL, 'feature_flags', 'x', 'false', NULL, NULL)");
        $store = new SettingsStore();
        (new \ReflectionProperty(SettingsStore::class, 'orm'))->setValue($store, $orm);
        $listener = new UserDeletedSettingsListener();
        (new \ReflectionProperty(UserDeletedSettingsListener::class, 'settings'))->setValue($listener, $store);
        (new \ReflectionProperty(UserDeletedSettingsListener::class, 'orm'))->setValue($listener, $orm);

        $listener->handle(new UserDeleted('u1', 'acme'));

        self::assertSame(['3', '4'], array_column($orm->getAdapter()->execute('SELECT id FROM platform_settings ORDER BY id')->rows, 'id'));
    }
}
