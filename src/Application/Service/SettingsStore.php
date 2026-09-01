<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
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

    /**
     * Where the per-request read cache lives.
     *
     * MEASURED before this existed: one GET / (OsShellPayload) issued 18 queries and 17 of
     * them were this table, the same statement each time — 94% of the request's database
     * work spent re-answering questions it had already answered. At ~0.2 ms a round trip
     * no slow-query threshold can ever see it; the cost is the count, not the duration.
     *
     * In {@see CoroutineLocal} and NOT an instance property, which would be the obvious
     * place and would be a data leak: this store is a shared `#[AsService]` singleton and
     * these rows are tenant-scoped, so an instance cache would serve one tenant's settings
     * to the next request on the same worker. Same reasoning, and the same shape, as
     * {@see \Semitexa\Rbac\Application\Service\RbacDecisionCache}.
     *
     * Scope is one request by construction, so a value written by another process mid-request
     * is not picked up until the next one. That is what per-request caching means, and it is
     * the same staleness window the request already had between its own first and last read.
     */
    private const CACHE_KEY = 'platform_settings.request_reads';

    // An absent row is cached as a plain null; presence is decided by array_key_exists, so
    // no sentinel is needed. An earlier version used one and widened the cache's value type
    // to SettingResource|string, which PHPStan correctly flagged: nothing then guaranteed
    // the only string in there was the sentinel. Caching the absence still matters - has()
    // on a missing key is the shape most likely to sit in a loop.

    #[InjectAsReadonly]
    protected OrmManager $orm;

    /**
     * Ambient-tenant seam (coroutine-local), resolved AT CALL TIME so this
     * shared singleton stays per-request-correct. Mirrors CalendarEventDbRepository.
     */
    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    private ?DomainRepository $repository = null;

    /** Test seam — production path uses property injection. */
    public function withTenantContextStore(TenantContextStoreInterface $store): self
    {
        $this->tenantContextStore = $store;

        return $this;
    }

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
        // useCache: false — this is compare-and-set. Deciding "seed it" from a value this
        // request read earlier would make the primitive answer about the past.
        if ($this->findResource($moduleKey, $key, null, useCache: false) === null) {
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
              WHERE tenant_id = :tenant_id
                AND module_key = :module_key
                AND setting_key = :setting_key
                AND user_id IS NULL
                AND value = :expected_value',
            [
                'next_value' => $encodedNext,
                'updated_at' => $now,
                'tenant_id' => $this->currentTenantId(),
                'module_key' => $moduleKey,
                'setting_key' => $key,
                'expected_value' => $encodedExpected,
            ],
        );

        // Unconditionally, not only on the winning branch: the findResource() above ran with
        // useCache: false but still refreshed the cache, so a LOSING claim would otherwise
        // leave the pre-race row cached and hand it to a later get() in this same request.
        $this->forgetRead($moduleKey, $key, null);

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
            $this->forgetRead($moduleKey, $key, $userId);

            return;
        }

        try {
            $userClause = $userId === null ? 'NULL' : ':user_id';
            $this->orm()->getAdapter()->execute(
                "INSERT INTO `platform_settings`
                    (id, tenant_id, user_id, module_key, setting_key, value, created_at, updated_at)
                 VALUES (:id, :tenant_id, {$userClause}, :module_key, :setting_key, :value, :created_at, :updated_at)",
                array_merge(
                    [
                        'id' => self::scopeId($this->currentTenantId(), $moduleKey, $key, $userId),
                        'tenant_id' => $this->currentTenantId(),
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

        // Both remaining paths wrote: the fresh INSERT and the lost-race UPDATE. Anything
        // this request read for the scope beforehand — including a cached "absent" — is
        // now wrong.
        $this->forgetRead($moduleKey, $key, $userId);
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
              WHERE tenant_id = :tenant_id AND module_key = :module_key AND setting_key = :setting_key AND {$userClause}",
            array_merge(
                [
                    'value' => $encoded,
                    'updated_at' => $now,
                    'tenant_id' => $this->currentTenantId(),
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
    private static function scopeId(string $tenantId, string $moduleKey, string $key, ?string $userId): string
    {
        return substr(hash('sha256', $tenantId . "\0" . ($userId ?? '') . "\0" . $moduleKey . "\0" . $key), 0, 32);
    }

    /**
     * @return array<string, mixed>
     */
    private function getAllByScope(string $moduleKey, ?string $userId): array
    {
        $this->validateModuleKey($moduleKey);

        $query = $this->scoped()->query()
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

        // useCache: false — a delete decided from a cached absence silently does nothing
        // when another request inserted the row after this one cached "not there".
        $existing = $this->findResource($moduleKey, $key, $userId, useCache: false);
        if ($existing !== null) {
            $this->scoped()->delete($existing);
        }
        $this->forgetRead($moduleKey, $key, $userId);
    }

    private function existsByScope(string $moduleKey, string $key, ?string $userId): bool
    {
        $this->validateModuleKey($moduleKey);
        $this->validateKey($key);

        return $this->findResource($moduleKey, $key, $userId) !== null;
    }

    /**
     * @param bool $useCache false only for compare-and-set, which must read the real row
     */
    private function findResource(
        string $moduleKey,
        string $key,
        ?string $userId,
        bool $useCache = true,
    ): ?SettingResource {
        $cacheKey = $this->readCacheKey($moduleKey, $key, $userId);
        if ($useCache) {
            /** @var array<string, SettingResource|null> $cache */
            $cache = CoroutineLocal::get(self::CACHE_KEY, []);
            if (array_key_exists($cacheKey, $cache)) {
                return $cache[$cacheKey];
            }
        }

        $query = $this->scoped()->query()
            ->where(SettingResource::column('module_key'), Operator::Equals, $moduleKey)
            ->where(SettingResource::column('setting_key'), Operator::Equals, $key);
        $this->applyUserScope($query, $userId);

        /** @var SettingResource|null $resource */
        $resource = $query->fetchOneAs(SettingResource::class, $this->orm()->getMapperRegistry());

        // Populated even when the caller asked to bypass: a fresh read is still the truth,
        // and leaving the stale entry behind would serve it to the next reader.
        $this->rememberRead($cacheKey, $resource);

        return $resource;
    }

    /**
     * Cache identity. The tenant belongs in the key even though the store is already
     * tenant-scoped at the query level: tenant fan-out walks several tenants inside one
     * coroutine, so a key without it would hand tenant B the row it read for tenant A.
     */
    private function readCacheKey(string $moduleKey, string $key, ?string $userId): string
    {
        // currentTenantId() is documented as never null - it returns the 'default'
        // sentinel instead - so coalescing here would imply a nullability the method
        // deliberately does not have. Only $userId is genuinely optional.
        //
        // The user scope is TAGGED rather than defaulted: a bare sentinel collides with a
        // real user whose id happens to equal it, and the two scopes hold different rows,
        // so the collision would serve a personal setting as the global one. 'g' can never
        // collide with 'u:' + anything.
        $scope = $userId === null ? 'g' : 'u:' . $userId;

        return $this->currentTenantId() . "\0" . $scope . "\0" . $moduleKey . "\0" . $key;
    }

    private function rememberRead(string $cacheKey, ?SettingResource $resource): void
    {
        /** @var array<string, SettingResource|null> $cache */
        $cache = CoroutineLocal::get(self::CACHE_KEY, []);
        $cache[$cacheKey] = $resource;
        CoroutineLocal::set(self::CACHE_KEY, $cache);
    }

    /** Drop one scope after a write, so the next read in this request sees what it wrote. */
    private function forgetRead(string $moduleKey, string $key, ?string $userId): void
    {
        /** @var array<string, SettingResource|null> $cache */
        $cache = CoroutineLocal::get(self::CACHE_KEY, []);
        unset($cache[$this->readCacheKey($moduleKey, $key, $userId)]);
        CoroutineLocal::set(self::CACHE_KEY, $cache);
    }

    private function applyUserScope(object $query, ?string $userId): void
    {
        if ($userId === null) {
            $query->whereNull(SettingResource::column('user_id'));

            return;
        }

        $query->where(SettingResource::column('user_id'), Operator::Equals, $userId);
    }

    /** Repository bound to the ambient tenant — the ORM gate filters every query. */
    private function scoped(): DomainRepository
    {
        return $this->repository()->forTenant($this->currentTenantId());
    }

    /**
     * Current tenant id, or the 'default' sentinel — never null, so the
     * fail-closed tenant filter and every raw-SQL WHERE bind a concrete value.
     */
    private function currentTenantId(): string
    {
        $context = isset($this->tenantContextStore) ? $this->tenantContextStore->tryGet() : null;

        return TenantContextAccess::tenantIdOrDefault($context);
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
