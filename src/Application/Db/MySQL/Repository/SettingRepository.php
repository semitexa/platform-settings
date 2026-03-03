<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Db\MySQL\Repository;

use Semitexa\Orm\Repository\AbstractRepository;
use Semitexa\Platform\Settings\Application\Db\MySQL\Model\SettingResource;
use Semitexa\Platform\Settings\Domain\Repository\SettingRepositoryInterface;

class SettingRepository extends AbstractRepository implements SettingRepositoryInterface
{
    protected function getResourceClass(): string
    {
        return SettingResource::class;
    }

    public function findByModuleAndKey(string $moduleKey, string $key): ?object
    {
        return $this->select()
            ->where('module_key', '=', $moduleKey)
            ->where('key', '=', $key)
            ->fetchOne();
    }

    public function findResourceByModuleAndKey(string $moduleKey, string $key): ?SettingResource
    {
        $resource = $this->select()
            ->where('module_key', '=', $moduleKey)
            ->where('key', '=', $key)
            ->fetchOneAsResource();
        return $resource instanceof SettingResource ? $resource : null;
    }

    /**
     * @return object[]
     */
    public function findAllByModule(string $moduleKey): array
    {
        return $this->select()
            ->where('module_key', '=', $moduleKey)
            ->fetchAll();
    }

    /**
     * All settings (tenant-scoped when tenancy is enabled). For admin UI.
     *
     * @return object[]
     */
    public function findAllSettings(int $limit = 500): array
    {
        return $this->select()->limit($limit)->fetchAll();
    }
}
