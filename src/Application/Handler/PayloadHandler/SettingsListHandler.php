<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Handler\PayloadHandler;

use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Contract\HandlerInterface;
use Semitexa\Core\Contract\PayloadInterface;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Core\Response;
use Semitexa\Orm\OrmManager;
use Semitexa\Platform\Settings\Application\Payload\Request\SettingsListPayload;
use Semitexa\Platform\Settings\Application\Db\MySQL\Repository\SettingRepository;

#[AsPayloadHandler(
    payload: SettingsListPayload::class,
    resource: \Semitexa\Core\Http\Response\GenericResponse::class,
)]
final class SettingsListHandler implements HandlerInterface
{
    #[InjectAsReadonly]
    protected AuthContextInterface $auth;

    public function handle(PayloadInterface $payload, ResourceInterface $resource): ResourceInterface
    {
        if (!$payload instanceof SettingsListPayload) {
            return Response::json(['error' => 'Invalid payload'], 400);
        }

        $scope = $payload->getScope();
        $moduleKey = $payload->getModuleKey();
        $userId = ($scope === 'user' && !$this->auth->isGuest()) ? $this->auth->getUser()->getId() : null;

        $list = OrmManager::run(function (OrmManager $orm) use ($moduleKey, $userId, $scope) {
            $repo = new SettingRepository($orm->getAdapter());
            $rows = $moduleKey !== ''
                ? $repo->findAllByModule($moduleKey, $userId)
                : $repo->findAllSettings(500, $userId);
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

        return Response::json(['scope' => $scope, 'settings' => $list]);
    }
}
