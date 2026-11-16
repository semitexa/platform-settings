<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Domain\Contract;

use Semitexa\Platform\Settings\Domain\Model\SettingRecord;

/**
 * Module settings that say who changed them and when — what an admin page shows
 * as "changed at X by Y". The global (non-personal) scope of the ambient tenant,
 * as {@see SettingsStoreInterface::set()} writes it.
 *
 * Its own contract rather than more methods on SettingsStoreInterface: that one
 * is implemented by test doubles across the framework and its consumers, and a
 * method added to it would break every one of them.
 *
 * A write through SettingsStoreInterface::set() names nobody, so it clears the
 * author: what describe() reports is always the last change's.
 */
interface AuditedSettingsStoreInterface
{
    /** set(), recording $byUserId (null: nobody named) as the author of the change. */
    public function setBy(string $moduleKey, string $key, mixed $value, ?string $byUserId): void;

    /**
     * Several keys of one module, all or none: one transaction, so a pair that
     * only works together (a public and a private key) is never half written.
     *
     * @param array<string, mixed> $values key => value
     */
    public function setManyBy(string $moduleKey, array $values, ?string $byUserId): void;

    /** The stored value with when and by whom it last changed; null when the key is not set. */
    public function describe(string $moduleKey, string $key): ?SettingRecord;
}
