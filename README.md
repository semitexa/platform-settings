# semitexa/platform-settings

System settings store for modules with per-tenant isolation and WM desktop integration.

## Purpose

Provides a key-value settings store scoped by module and optional tenant. Any module can persist its own configuration through `SettingsStoreInterface`. When tenancy is active, settings are automatically isolated per tenant.

## Role in Semitexa

Depends on Core, ORM, and Platform WM. Used by platform modules to store runtime configuration. Exposes a WM desktop app for browsing settings across modules.

## Key Features

- `SettingsStoreInterface` contract: `get`, `set`, `getAll`, `remove` per module key
- Automatic tenant isolation via `tenant_id` scoping
- JSON-serializable values (scalar, array, object)
- ORM-backed persistence (`platform_settings` table, auto-synced via `orm:sync`)
- System Settings WM app for desktop browsing (read-only overview)
- Global fallback when tenancy is disabled (`tenant_id = NULL`)

## Notes

Settings are scoped by `(module_key, key, tenant_id)`. Module keys identify the owning package (e.g., `platform-user`, `platform-wm`). The WM app provides visibility but modules interact programmatically via the injected `SettingsStoreInterface`.
