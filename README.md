# Semitexa Platform Settings

System settings store for modules. Any module can persist its own key-value settings. **Multi-tenant aware**: when tenancy is enabled, settings are isolated per tenant (`tenant_id` is injected automatically).

## Usage in modules

Inject the contract and read/write by module key:

```php
use Semitexa\Platform\Settings\Contract\SettingsStoreInterface;

// In your handler or service:
$this->settings->set('my-module', 'theme', 'dark');
$theme = $this->settings->get('my-module', 'theme');
$all = $this->settings->getAll('my-module');
$this->settings->remove('my-module', 'theme');
```

- **module_key**: e.g. `platform-user`, `platform-wm` — identifies the owning module (max 128 chars).
- **key**: setting name within the module (max 255 chars).
- **value**: any JSON-serializable value (scalar, array, object).

## WM app

The **System Settings** app appears on the platform desktop (icon ⚙). It opens `/platform/settings` and lists all stored settings grouped by module (read-only overview). Modules use `SettingsStoreInterface` programmatically to read/write.

## Database

Table `platform_settings` is created/updated by the ORM sync. Run:

```bash
bin/semitexa orm:sync
```

(or `docker compose exec app php vendor/bin/semitexa orm:sync`). The collector discovers `SettingResource` (via `#[FromTable]`) and applies the schema diff.

## Tenant behaviour

- With **tenancy disabled** or no tenant resolved: `tenant_id` stays `NULL`; all settings are global.
- With **tenancy enabled** and tenant resolved: `tenant_id` is set by the scope; each tenant has its own settings per (module_key, key).
