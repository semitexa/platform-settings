<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Payload\Request;

use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Attributes\RequiresAuth;
use Semitexa\Core\Contract\PayloadInterface;
use Semitexa\Core\Http\Response\GenericResponse;

#[AsPayload(path: '/platform/settings', methods: ['GET'], responseWith: GenericResponse::class)]
#[RequiresAuth]
class SettingsPagePayload implements PayloadInterface
{
}
