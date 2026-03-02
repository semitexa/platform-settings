-- Table for module settings (multi-tenant aware: tenant_id injected when tenancy is enabled).
-- Run this if ORM sync does not create it, or create via your migration flow.

CREATE TABLE IF NOT EXISTS `platform_settings` (
  `id` BINARY(16) NOT NULL,
  `tenant_id` VARCHAR(64) NULL DEFAULT NULL,
  `module_key` VARCHAR(128) NOT NULL,
  `key` VARCHAR(255) NOT NULL,
  `value` LONGTEXT NOT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_platform_settings_tenant_module_key` (`tenant_id`, `module_key`, `key`),
  KEY `idx_platform_settings_module_key` (`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
