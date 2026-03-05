<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Handler\PayloadHandler;

use Semitexa\Core\Attributes\AsPayloadHandler;
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

        if ($moduleKey === '' || $key === '') {
            return Response::json(['error' => 'module_key and key are required'], 400);
        }

        try {
            $this->settings->set($moduleKey, $key, $value);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }

        return Response::json(['ok' => true]);
    }
}
