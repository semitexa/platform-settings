<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Handler\PayloadHandler;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Http\Response\GenericResponse;
use Semitexa\Orm\OrmManager;
use Semitexa\Platform\Settings\Application\Payload\Request\SettingsListPayload;
use Semitexa\Platform\Settings\Application\Db\MySQL\Repository\SettingRepository;

#[AsPayloadHandler(
    payload: SettingsListPayload::class,
    resource: GenericResponse::class,
)]
final class SettingsListHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected AuthContextInterface $auth;

    #[InjectAsReadonly]
    protected ?ContainerInterface $container = null;

    public function handle(SettingsListPayload $payload, GenericResponse $resource): GenericResponse
    {
        $scope = $payload->getScope();
        $moduleKey = $payload->getModuleKey();

        if ($this->auth->isGuest()) {
            throw new AccessDeniedException('Authentication required to view settings.');
        }

        if ($scope === 'global' && !$this->canManageGlobalSettings()) {
            throw new AccessDeniedException('Insufficient permissions to view global settings.');
        }

        $userId = ($scope === 'user' && !$this->auth->isGuest()) ? $this->auth->getUser()->getId() : null;

        $list = OrmManager::run(function (OrmManager $orm) use ($moduleKey, $userId, $scope) {
            $repo = new SettingRepository($orm->getAdapter());
            $rows = $moduleKey !== ''
                ? $repo->findAllByModule($moduleKey, $userId)
                : $repo->findAllSettings(500, $scope === 'user' ? 'user' : 'global', $userId);
            $out = [];
            foreach ($rows as $s) {
                $out[] = [
                    'module_key' => $s->moduleKey,
                    'key' => $s->key,
                    'value' => $s->value === '' ? null : json_decode($s->value, true, 512, \JSON_THROW_ON_ERROR),
                    'scope' => $s->userId === null ? 'global' : 'user',
                ];
            }
            return $out;
        });

        $resource->setContext(['scope' => $scope, 'settings' => $list]);
        return $resource;
    }

    private function canManageGlobalSettings(): bool
    {
        try {
            $rbacClass = 'Semitexa\\Platform\\User\\Domain\\Service\\RbacServiceInterface';
            if (!interface_exists($rbacClass) && !class_exists($rbacClass)) {
                return false;
            }
            $rbac = $this->container?->get($rbacClass);
        } catch (\Throwable) {
            $rbac = null;
        }

        if ($rbac === null) {
            return false;
        }

        return $rbac->userHasPermission($this->auth->getUser()->getId(), 'platform.settings.manage_global');
    }
}
