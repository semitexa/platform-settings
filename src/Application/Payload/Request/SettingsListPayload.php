<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Payload\Request;

use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Attributes\RequiresAuth;
use Semitexa\Core\Http\Response\GenericResponse;

#[AsPayload(path: '/api/platform/settings', methods: ['GET'], responseWith: GenericResponse::class)]
#[RequiresAuth]
class SettingsListPayload
{
    public string $scope = 'user';
    public string $module_key = '';

    public function getScope(): string
    {
        $scope = strtolower(trim($this->scope));
        return in_array($scope, ['user', 'global'], true) ? $scope : 'user';
    }

    public function getModuleKey(): string
    {
        return trim($this->module_key);
    }
}
