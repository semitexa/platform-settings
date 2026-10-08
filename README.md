# semitexa/platform-settings

System settings store for modules with per-tenant isolation.

## Install

Included in every project created by the installer (https://semitexa.com/install.sh).

## Purpose

Provides a key-value settings store scoped by module and optional tenant. Any module can persist its own configuration through `SettingsStoreInterface`. When tenancy is active, settings are automatically isolated per tenant.

## Role in Semitexa

Depends on Core, ORM and Update (a data patch backfills `tenant_id`). Used by platform modules to store runtime configuration.

## Key Features

- `SettingsStoreInterface` contract: `get`, `set`, `getAll`, `remove`, `has`, `claim` per module key, plus `*ForUser` variants for personal settings
- Automatic tenant isolation via `tenant_id` scoping
- JSON-serializable values (scalar, array, object)
- ORM-backed persistence (`platform_settings` table, auto-synced via `orm:sync`)
- Without a tenant context, settings are stored under the `default` tenant

## Notes

Settings are scoped by `(tenant_id, user_id, module_key, key)`; `user_id` is NULL for module-wide settings. Module keys identify the owning package (e.g., `platform-user`). Modules interact programmatically via the injected `SettingsStoreInterface`.
