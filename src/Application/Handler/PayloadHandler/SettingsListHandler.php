<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Handler\PayloadHandler;

use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Attributes\InjectAsReadonly;
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
    public function handle(PayloadInterface $payload, ResourceInterface $resource): ResourceInterface
    {
        $list = OrmManager::run(function (OrmManager $orm) {
            $repo = new SettingRepository($orm->getAdapter());
            $rows = $repo->findAllSettings();
            $out = [];
            foreach ($rows as $s) {
                $out[] = [
                    'module_key' => $s->moduleKey,
                    'key' => $s->key,
                    'value' => $s->value === '' ? null : json_decode($s->value, true, 512, \JSON_THROW_ON_ERROR),
                ];
            }
            return $out;
        });

        return Response::json(['settings' => $list]);
    }
}
