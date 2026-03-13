<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Handler\PayloadHandler;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Core\Http\Response\GenericResponse;
use Semitexa\Platform\Settings\Application\Payload\Request\SettingsSetPayload;
use Semitexa\Platform\Settings\Contract\SettingsStoreInterface;

#[AsPayloadHandler(
    payload: SettingsSetPayload::class,
    resource: GenericResponse::class,
)]
final class SettingsSetHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected AuthContextInterface $auth;

    #[InjectAsReadonly]
    protected ?ContainerInterface $container = null;

    public function __construct(
        private readonly SettingsStoreInterface $settings,
    ) {
    }

    public function handle(SettingsSetPayload $payload, GenericResponse $resource): GenericResponse
    {
        $moduleKey = $payload->getModuleKey();
        $key = $payload->getKey();
        $value = $payload->getValue();
        $scope = $payload->getScope();

        if ($moduleKey === '' || $key === '') {
            throw new ValidationException(['module_key' => ['module_key and key are required']]);
        }

        if ($scope === 'user') {
            $this->settings->setForUser($moduleKey, $key, $value, $this->auth->getUser()->getId());
        } else {
            if (!$this->canManageGlobalSettings()) {
                throw new AccessDeniedException('Insufficient permissions to manage global settings.');
            }
            $this->settings->set($moduleKey, $key, $value);
        }

        $resource->setContext(['ok' => true, 'scope' => $scope]);
        return $resource;
    }

    private function canManageGlobalSettings(): bool
    {
        if ($this->auth->isGuest()) {
            return false;
        }

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
