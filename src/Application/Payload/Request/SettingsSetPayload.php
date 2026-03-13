<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Payload\Request;

use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Attributes\RequiresAuth;
use Semitexa\Core\Http\Response\GenericResponse;

#[AsPayload(path: '/api/platform/settings', methods: ['POST'], responseWith: GenericResponse::class)]
#[RequiresAuth]
class SettingsSetPayload
{
    public string $module_key = '';
    public string $key = '';
    public string $scope = 'global';
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

    public function getScope(): string
    {
        $scope = strtolower(trim($this->scope));
        if ($scope === '') {
            return 'global';
        }
        if (!in_array($scope, ['user', 'global'], true)) {
            throw new \InvalidArgumentException("Invalid scope '{$this->scope}'. Allowed: user, global");
        }
        return $scope;
    }

    public function setScope(string $scope): void
    {
        $this->scope = $scope;
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
