<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Service;

use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Auth\Event\UserDeleted;
use Semitexa\Core\Event\EventExecution;
use Semitexa\Orm\OrmManager;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * A deleted user's own settings and feature flag overrides go with the user:
 * they stayed, and an id given to a new account later would have taken them
 * over. Sync, in the deleting transaction: they go only if the user does.
 */
#[AsEventListener(event: UserDeleted::class, execution: EventExecution::Sync)]
final class UserDeletedSettingsListener
{
    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

    #[InjectAsReadonly]
    protected OrmManager $orm;

    /**
     * Every site's rows: user ids are unique across sites. Only for the
     * shipped store, whose table this is; a project's own store listens to
     * UserDeleted itself.
     */
    public function handle(UserDeleted $event): void
    {
        if ($event->userId === '' || !$this->settings instanceof SettingsStore) {
            return;
        }
        $this->orm->getQueryAdapter()->execute(
            'DELETE FROM platform_settings WHERE user_id = :user_id',
            ['user_id' => $event->userId],
        );
    }
}
