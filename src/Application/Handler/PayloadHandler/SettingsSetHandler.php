<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Handler\PayloadHandler;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Contract\HandlerInterface;
use Semitexa\Core\Contract\PayloadInterface;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Core\Response;
use Semitexa\Platform\Settings\Application\Payload\Request\SettingsSetPayload;
use Semitexa\Platform\Settings\Contract\SettingsStoreInterface;

#[AsPayloadHandler(
    payload: SettingsSetPayload::class,
    resource: \Semitexa\Core\Http\Response\GenericResponse::class,
)]
final class SettingsSetHandler implements HandlerInterface
{
    #[InjectAsReadonly]
    protected AuthContextInterface $auth;

    #[InjectAsReadonly]
    protected ?ContainerInterface $container = null;

    public function __construct(
        private readonly SettingsStoreInterface $settings,
    ) {
    }

    public function handle(PayloadInterface $payload, ResourceInterface $resource): ResourceInterface
    {
        if (!$payload instanceof SettingsSetPayload) {
            return Response::json(['error' => 'Invalid payload'], 400);
        }

        $moduleKey = $payload->getModuleKey();
        $key = $payload->getKey();
        $value = $payload->getValue();
        $scope = $payload->getScope();

        if ($moduleKey === '' || $key === '') {
            return Response::json(['error' => 'module_key and key are required'], 400);
        }

        try {
            if ($scope === 'user') {
                if ($this->auth->isGuest()) {
                    return Response::json(['error' => 'Unauthorized'], 401);
                }
                $this->settings->setForUser($moduleKey, $key, $value, $this->auth->getUser()->getId());
            } else {
                if ($this->auth->isGuest()) {
                    return Response::json(['error' => 'Unauthorized'], 401);
                }
                if (!$this->canManageGlobalSettings()) {
                    return Response::json(['error' => 'Forbidden'], 403);
                }
                $this->settings->set($moduleKey, $key, $value);
            }
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }

        return Response::json(['ok' => true, 'scope' => $scope]);
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
