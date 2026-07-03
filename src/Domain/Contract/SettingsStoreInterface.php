<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Domain\Contract;

/**
 * Store and retrieve module-specific settings.
 * Multi-tenant aware: when tenancy is enabled, settings are isolated per tenant.
 * Any module can use this to persist its own key-value settings.
 */
interface SettingsStoreInterface
{
    /**
     * Get a setting value for a module. Returns null if not set.
     */
    public function get(string $moduleKey, string $key): mixed;
    public function getForUser(string $moduleKey, string $key, string $userId): mixed;

    /**
     * Set a setting value. Value must be JSON-serializable (scalar, array, object).
     */
    public function set(string $moduleKey, string $key, mixed $value): void;
    public function setForUser(string $moduleKey, string $key, mixed $value, string $userId): void;

    /**
     * Get all settings for a module as key => value.
     *
     * @return array<string, mixed>
     */
    public function getAll(string $moduleKey): array;
    public function getAllForUser(string $moduleKey, string $userId): array;

    /**
     * Remove a setting.
     */
    public function remove(string $moduleKey, string $key): void;
    public function removeForUser(string $moduleKey, string $key, string $userId): void;

    /**
     * Check if a setting exists.
     */
    public function has(string $moduleKey, string $key): bool;
    public function hasForUser(string $moduleKey, string $key, string $userId): bool;
}
