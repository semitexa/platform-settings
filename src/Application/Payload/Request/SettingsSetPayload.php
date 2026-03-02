<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Payload\Request;

use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Attributes\RequiresAuth;
use Semitexa\Core\Contract\PayloadInterface;
use Semitexa\Core\Http\Response\GenericResponse;

#[AsPayload(path: '/api/platform/settings', methods: ['POST'], responseWith: GenericResponse::class)]
#[RequiresAuth]
class SettingsSetPayload implements PayloadInterface
{
    public string $module_key = '';
    public string $key = '';
    public mixed $value = null;

    public function getModuleKey(): string
    {
        return $this->module_key;
    }

    public function setModuleKey(string $module_key): void
    {
        $this->module_key = $module_key;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): void
    {
        $this->key = $key;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): void
    {
        $this->value = $value;
    }
}
