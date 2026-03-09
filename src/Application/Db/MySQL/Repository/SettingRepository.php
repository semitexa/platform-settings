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

    public function findByModuleAndKey(string $moduleKey, string $key, ?string $userId = null): ?object
    {
        $q = $this->select()
            ->where('module_key', '=', $moduleKey)
            ->where('key', '=', $key);
        $this->applyUserScope($q, $userId);
        return $q->fetchOne();
    }

    public function findResourceByModuleAndKey(string $moduleKey, string $key, ?string $userId = null): ?SettingResource
    {
        $q = $this->select()
            ->where('module_key', '=', $moduleKey)
            ->where('key', '=', $key);
        $this->applyUserScope($q, $userId);
        $resource = $q->fetchOneAsResource();
        return $resource instanceof SettingResource ? $resource : null;
    }

    /**
     * @return object[]
     */
    public function findAllByModule(string $moduleKey, ?string $userId = null): array
    {
        $q = $this->select()->where('module_key', '=', $moduleKey);
        $this->applyUserScope($q, $userId);
        return $q->fetchAll();
    }

    /**
     * All settings (tenant-scoped when tenancy is enabled). For admin UI.
     *
     * @return object[]
     */
    public function findAllSettings(int $limit = 500, ?string $userId = null): array
    {
        $q = $this->select()->limit($limit);
        $this->applyUserScope($q, $userId);
        return $q->fetchAll();
    }

    private function applyUserScope(\Semitexa\Orm\Query\SelectQuery $q, ?string $userId): void
    {
        if ($userId === '') {
            throw new \InvalidArgumentException('userId must not be empty string');
        }
        if ($userId === null) {
            $q->whereNull('user_id');
            return;
        }
        $q->where('user_id', '=', $userId);
    }
}
