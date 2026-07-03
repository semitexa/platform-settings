<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Platform\Settings\Application\Db\MySQL\Model\SettingResource;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * Database-backed, module-scoped key-value settings store.
 *
 * Values are JSON-serialised into a single `platform_settings` row keyed by
 * (tenant, user, module, key). Global settings pass `userId = null`; personal
 * settings pass a non-empty user id. Multi-tenant aware via the resource's
 * {@see \Semitexa\Orm\Attribute\TenantScoped} policy.
 *
 * Follows the current ORM access pattern: inject {@see OrmManager}, memoise a
 * {@see DomainRepository}, drive typed queries off the resource's column refs.
 */
#[SatisfiesServiceContract(of: SettingsStoreInterface::class)]
final class SettingsStore implements SettingsStoreInterface
{
    private const MODULE_KEY_MAX = 128;
    private const KEY_MAX = 255;

    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?DomainRepository $repository = null;

    public function get(string $moduleKey, string $key): mixed
    {
        return $this->getByScope($moduleKey, $key, null);
    }

    public function getForUser(string $moduleKey, string $key, string $userId): mixed
    {
        $this->requireUserId($userId);

        return $this->getByScope($moduleKey, $key, $userId);
    }

    public function set(string $moduleKey, string $key, mixed $value): void
    {
        $this->setByScope($moduleKey, $key, $value, null);
    }

    public function setForUser(string $moduleKey, string $key, mixed $value, string $userId): void
    {
        $this->requireUserId($userId);
        $this->setByScope($moduleKey, $key, $value, $userId);
    }

    public function getAll(string $moduleKey): array
    {
        return $this->getAllByScope($moduleKey, null);
    }

    public function getAllForUser(string $moduleKey, string $userId): array
    {
        $this->requireUserId($userId);

        return $this->getAllByScope($moduleKey, $userId);
    }

    public function remove(string $moduleKey, string $key): void
    {
        $this->removeByScope($moduleKey, $key, null);
    }

    public function removeForUser(string $moduleKey, string $key, string $userId): void
    {
        $this->requireUserId($userId);
        $this->removeByScope($moduleKey, $key, $userId);
    }

    public function has(string $moduleKey, string $key): bool
    {
        return $this->existsByScope($moduleKey, $key, null);
    }

    public function hasForUser(string $moduleKey, string $key, string $userId): bool
    {
        $this->requireUserId($userId);

        return $this->existsByScope($moduleKey, $key, $userId);
    }

    private function getByScope(string $moduleKey, string $key, ?string $userId): mixed
    {
        $this->validateModuleKey($moduleKey);
        $this->validateKey($key);

        $resource = $this->findResource($moduleKey, $key, $userId);
        if ($resource === null || $resource->value === '') {
            return null;
        }

        return json_decode($resource->value, true, 512, \JSON_THROW_ON_ERROR);
    }

    private function setByScope(string $moduleKey, string $key, mixed $value, ?string $userId): void
    {
        $this->validateModuleKey($moduleKey);
        $this->validateKey($key);

        $encoded = json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $now = new \DateTimeImmutable();
        $existing = $this->findResource($moduleKey, $key, $userId);

        if ($existing !== null) {
            $this->repository()->update(new SettingResource(
                id: $existing->id,
                tenant_id: $existing->tenant_id,
                user_id: $existing->user_id,
                module_key: $existing->module_key,
                setting_key: $existing->setting_key,
                value: $encoded,
                created_at: $existing->created_at,
                updated_at: $now,
            ));

            return;
        }

        $this->repository()->insert(new SettingResource(
            id: Uuid7::generate(),
            tenant_id: null,
            user_id: $userId,
            module_key: $moduleKey,
            setting_key: $key,
            value: $encoded,
            created_at: $now,
            updated_at: $now,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function getAllByScope(string $moduleKey, ?string $userId): array
    {
        $this->validateModuleKey($moduleKey);

        $query = $this->repository()->query()
            ->where(SettingResource::column('module_key'), Operator::Equals, $moduleKey);
        $this->applyUserScope($query, $userId);

        /** @var list<SettingResource> $rows */
        $rows = $query->fetchAllAs(SettingResource::class, $this->orm()->getMapperRegistry());

        $out = [];
        foreach ($rows as $row) {
            $out[$row->setting_key] = $row->value === ''
                ? null
                : json_decode($row->value, true, 512, \JSON_THROW_ON_ERROR);
        }

        return $out;
    }

    private function removeByScope(string $moduleKey, string $key, ?string $userId): void
    {
        $this->validateModuleKey($moduleKey);
        $this->validateKey($key);

        $existing = $this->findResource($moduleKey, $key, $userId);
        if ($existing !== null) {
            $this->repository()->delete($existing);
        }
    }

    private function existsByScope(string $moduleKey, string $key, ?string $userId): bool
    {
        $this->validateModuleKey($moduleKey);
        $this->validateKey($key);

        return $this->findResource($moduleKey, $key, $userId) !== null;
    }

    private function findResource(string $moduleKey, string $key, ?string $userId): ?SettingResource
    {
        $query = $this->repository()->query()
            ->where(SettingResource::column('module_key'), Operator::Equals, $moduleKey)
            ->where(SettingResource::column('setting_key'), Operator::Equals, $key);
        $this->applyUserScope($query, $userId);

        /** @var SettingResource|null $resource */
        $resource = $query->fetchOneAs(SettingResource::class, $this->orm()->getMapperRegistry());

        return $resource;
    }

    private function applyUserScope(object $query, ?string $userId): void
    {
        if ($userId === null) {
            $query->whereNull(SettingResource::column('user_id'));

            return;
        }

        $query->where(SettingResource::column('user_id'), Operator::Equals, $userId);
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(SettingResource::class, SettingResource::class);
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }

    private function requireUserId(string $userId): void
    {
        if ($userId === '') {
            throw new \InvalidArgumentException('userId is required');
        }
    }

    private function validateModuleKey(string $moduleKey): void
    {
        if ($moduleKey === '' || strlen($moduleKey) > self::MODULE_KEY_MAX) {
            throw new \InvalidArgumentException('moduleKey must be 1-' . self::MODULE_KEY_MAX . ' characters');
        }
    }

    private function validateKey(string $key): void
    {
        if ($key === '' || strlen($key) > self::KEY_MAX) {
            throw new \InvalidArgumentException('key must be 1-' . self::KEY_MAX . ' characters');
        }
    }
}
