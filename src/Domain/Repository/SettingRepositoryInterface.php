<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Domain\Repository;

use Semitexa\Platform\Settings\Application\Db\MySQL\Model\SettingResource;

interface SettingRepositoryInterface
{
    public function findByModuleAndKey(string $moduleKey, string $key): ?object;

    public function findResourceByModuleAndKey(string $moduleKey, string $key): ?SettingResource;

    /**
     * @return list<object>
     */
    public function findAllByModule(string $moduleKey): array;

    /**
     * @return list<object>
     */
    public function findAllSettings(int $limit = 500): array;

    public function save(object $resource): void;

    public function delete(object $resource): void;
}
