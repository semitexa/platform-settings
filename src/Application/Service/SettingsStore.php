<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
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

    public function claim(string $moduleKey, string $key, mixed $expected, mixed $next): bool
    {
        $this->validateModuleKey($moduleKey);
        $this->validateKey($key);

        $encodedNext = json_encode($next, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');

        // Absent setting: seed it (best-effort — the first-ever write is not
        // a contended path; the nullable-scope unique index treats NULLs as
        // distinct, so a DB-level first-run claim is not available anyway).
        if ($this->findResource($moduleKey, $key, null) === null) {
            $this->setByScope($moduleKey, $key, $next, null);

            return true;
        }

        // Existing setting: atomic compare-and-set. Only one concurrent caller
        // whose $expected still matches the stored value performs the write.
        // Each placeholder is bound once — native prepares reject a reused name.
        $encodedExpected = json_encode($expected, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $result = $this->orm()->getAdapter()->execute(
            'UPDATE `platform_settings`
                SET value = :next_value, updated_at = :updated_at
              WHERE module_key = :module_key
                AND setting_key = :setting_key
                AND user_id IS NULL
                AND value = :expected_value',
            [
                'next_value' => $encodedNext,
                'updated_at' => $now,
                'module_key' => $moduleKey,
                'setting_key' => $key,
                'expected_value' => $encodedExpected,
            ],
        );

        return $result->rowCount === 1;
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

    /**
     * Write a setting, atomically. The old find-then-insert let two concurrent
     * writers of the same NEW scope both see "no row" and both INSERT, creating
     * DUPLICATE rows — the `uniq_platform_settings_scope` index cannot stop them
     * because its `tenant_id`/`user_id` columns are NULL and MySQL treats NULLs
     * as distinct in a unique index.
     *
     * Instead: UPDATE by scope first (atomic for any existing row, including the
     * legacy random-id rows); if nothing matched, INSERT with a DETERMINISTIC
     * scope-derived id, so two concurrent first-writers collide on the PRIMARY
     * KEY (always enforced, NULL-independent) — the loser catches the violation
     * and re-UPDATEs the now-present row. Portable across MySQL and SQLite
     * (plain UPDATE + INSERT + PK-violation catch, no dialect-specific upsert).
     */
    private function setByScope(string $moduleKey, string $key, mixed $value, ?string $userId): void
    {
        $this->validateModuleKey($moduleKey);
        $this->validateKey($key);

        $encoded = json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');

        if ($this->updateScopeValue($moduleKey, $key, $userId, $encoded, $now) > 0) {
            return;
        }

        try {
            $userClause = $userId === null ? 'NULL' : ':user_id';
            $this->orm()->getAdapter()->execute(
                "INSERT INTO `platform_settings`
                    (id, tenant_id, user_id, module_key, setting_key, value, created_at, updated_at)
                 VALUES (:id, NULL, {$userClause}, :module_key, :setting_key, :value, :created_at, :updated_at)",
                array_merge(
                    [
                        'id' => self::scopeId($moduleKey, $key, $userId),
                        'module_key' => $moduleKey,
                        'setting_key' => $key,
                        'value' => $encoded,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $userId === null ? [] : ['user_id' => $userId],
                ),
            );
        } catch (\Throwable) {
            // Lost the first-write race: another writer inserted the same
            // deterministic id first (PK violation). The row exists now — update it.
            $this->updateScopeValue($moduleKey, $key, $userId, $encoded, $now);
        }
    }

    /**
     * Atomic UPDATE of a scope's value; returns affected rows (0 = no such row).
     * Each placeholder is bound once — native prepares reject a reused name.
     */
    private function updateScopeValue(string $moduleKey, string $key, ?string $userId, string $encoded, string $now): int
    {
        $userClause = $userId === null ? 'user_id IS NULL' : 'user_id = :user_id';
        $result = $this->orm()->getAdapter()->execute(
            "UPDATE `platform_settings`
                SET value = :value, updated_at = :updated_at
              WHERE module_key = :module_key AND setting_key = :setting_key AND {$userClause}",
            array_merge(
                [
                    'value' => $encoded,
                    'updated_at' => $now,
                    'module_key' => $moduleKey,
                    'setting_key' => $key,
                ],
                $userId === null ? [] : ['user_id' => $userId],
            ),
        );

        return $result->rowCount;
    }

    /**
     * Deterministic id for a setting scope — the same (module, key, user) always
     * hashes to the same 32-char id, so concurrent first-writers collide on the
     * primary key instead of creating duplicate rows. Fits the Varchar(36) id
     * column; distinct in shape from the legacy UUIDv7 ids, which stay valid.
     */
    private static function scopeId(string $moduleKey, string $key, ?string $userId): string
    {
        return substr(hash('sha256', ($userId ?? '') . "\0" . $moduleKey . "\0" . $key), 0, 32);
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
