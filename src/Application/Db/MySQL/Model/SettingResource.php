<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

/**
 * ORM resource for the platform settings key-value store.
 *
 * A setting is scoped by (tenant, user, module, key):
 *  - `tenant_id` null   → not multi-tenant / global tenant;
 *  - `user_id`   null   → a global setting for the module;
 *  - `user_id`   set    → a personal setting for that user.
 *
 * Shape follows the current ORM contract (enforced by
 * {@see \Semitexa\Orm\Metadata\ResourceModelMetadataValidator}): a
 * `final readonly` class with constructor-promoted `#[Column]` properties.
 * The UUID id is supplied by the store ({@see \Semitexa\Platform\Settings\Application\Service\SettingsStore}),
 * so the primary key uses the `manual` strategy; timestamps are explicit
 * columns (the mutable `HasTimestamps`/`HasUuidV7` traits are incompatible
 * with a readonly resource).
 */
#[FromTable(name: 'platform_settings')]
#[Index(columns: ['tenant_id', 'user_id', 'module_key', 'setting_key'], unique: true, name: 'uniq_platform_settings_scope')]
final readonly class SettingResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        /** Reserved for multi-tenant scoping (schema-ready); null until tenancy is wired. */
        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenant_id,

        /** Null = global module setting; non-null = personal setting for that user. */
        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $user_id,

        #[Column(type: MySqlType::Varchar, length: 128)]
        public string $module_key,

        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $setting_key,

        #[Column(type: MySqlType::LongText)]
        public string $value,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $created_at,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $updated_at,
    ) {}
}
