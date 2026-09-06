<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Service;

use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Platform\Settings\Domain\Model\Setting;

/**
 * What one request has already read from `platform_settings`.
 *
 * Extracted from {@see SettingsStore} rather than added to it: the store was
 * already a recorded structural outlier, and the module warm below would have
 * been its thirty-fourth method. Cache identity, the stored rows and the warm
 * marker are one concern and move together.
 *
 * ## Two things are remembered, not one
 *
 * Rows answer "what is this key" for keys that were read. They cannot answer
 * "is this key absent" for a key nobody asked for — and that is the question
 * that mattered. MEASURED on the OS shell: seven services each read a DIFFERENT
 * key of the same module (OsPreferences, SkinStore, OsSessionStore, Weaver,
 * OsGraph, InputLayoutStore, OpenDialogStore — every one declaring
 * `MODULE = 'os'`), so a row cache collapsed none of them: seven round trips.
 *
 * So a module is loaded whole on first touch and marked WARM. After that a key
 * that is not among the rows is known to be absent without asking, which is the
 * difference between "we looked and it is not there" and "we never looked".
 *
 * ## Scope
 *
 * Everything is keyed by tenant AND user scope. Tenant fan-out walks several
 * tenants inside one coroutine, so a key without the tenant hands tenant B the
 * row it read for tenant A; and the global and per-user scopes hold different
 * rows, so a marker shared between them answers for a set it never loaded.
 *
 * The user scope is TAGGED (`g` versus `u:<id>`) rather than defaulted: a bare
 * sentinel collides with a real user whose id happens to equal it, and that
 * collision would serve a personal setting as the global one.
 *
 * State lives in {@see CoroutineLocal}, so it is per request by construction —
 * a value written by another process mid-request is not seen until the next one.
 * That is what per-request caching means.
 */
final class SettingsReadCache
{
    private const ROWS = 'platform_settings.request_reads';

    private const WARM = 'platform_settings.request_warm_modules';

    /** Whether this exact key has been read (including a read that found nothing). */
    public function knows(string $tenantId, string $moduleKey, string $key, ?string $userId): bool
    {
        return array_key_exists($this->rowKey($tenantId, $moduleKey, $key, $userId), $this->rows());
    }

    /** The row for a key this cache {@see knows()}; null both for absent and unknown. */
    public function read(string $tenantId, string $moduleKey, string $key, ?string $userId): ?Setting
    {
        return $this->rows()[$this->rowKey($tenantId, $moduleKey, $key, $userId)] ?? null;
    }

    public function remember(string $tenantId, string $moduleKey, string $key, ?string $userId, ?Setting $row): void
    {
        $rows = $this->rows();
        $rows[$this->rowKey($tenantId, $moduleKey, $key, $userId)] = $row;
        CoroutineLocal::set(self::ROWS, $rows);
    }

    /**
     * Drop one key after a write, and the module's warm marker with it.
     *
     * The marker has to go: a key CREATED after the warm is not among the rows
     * the warm loaded, so a marker left standing would answer "absent" for the
     * row this request just wrote, for the rest of the request.
     */
    public function forget(string $tenantId, string $moduleKey, string $key, ?string $userId): void
    {
        $rows = $this->rows();
        unset($rows[$this->rowKey($tenantId, $moduleKey, $key, $userId)]);
        CoroutineLocal::set(self::ROWS, $rows);

        $warm = $this->warm();
        unset($warm[$this->moduleKey($tenantId, $moduleKey, $userId)]);
        CoroutineLocal::set(self::WARM, $warm);
    }

    /** @param list<Setting> $rows every row of the module, in this scope */
    public function rememberModule(string $tenantId, string $moduleKey, ?string $userId, array $rows): void
    {
        foreach ($rows as $row) {
            $this->remember($tenantId, $moduleKey, $row->getSettingKey(), $userId, $row);
        }

        $warm = $this->warm();
        $warm[$this->moduleKey($tenantId, $moduleKey, $userId)] = true;
        CoroutineLocal::set(self::WARM, $warm);
    }

    /** Whether every row of this module and scope is already loaded. */
    public function isWarm(string $tenantId, string $moduleKey, ?string $userId): bool
    {
        return isset($this->warm()[$this->moduleKey($tenantId, $moduleKey, $userId)]);
    }

    /** @return array<string, Setting|null> */
    private function rows(): array
    {
        /** @var array<string, Setting|null> $rows */
        $rows = CoroutineLocal::get(self::ROWS, []);

        return $rows;
    }

    /** @return array<string, true> */
    private function warm(): array
    {
        /** @var array<string, true> $warm */
        $warm = CoroutineLocal::get(self::WARM, []);

        return $warm;
    }

    private function rowKey(string $tenantId, string $moduleKey, string $key, ?string $userId): string
    {
        return $this->moduleKey($tenantId, $moduleKey, $userId) . "\0" . $key;
    }

    private function moduleKey(string $tenantId, string $moduleKey, ?string $userId): string
    {
        return $tenantId . "\0" . ($userId === null ? 'g' : 'u:' . $userId) . "\0" . $moduleKey;
    }
}
