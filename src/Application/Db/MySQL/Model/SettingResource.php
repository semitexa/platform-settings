<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\Orm\Contract\DomainMappable;
use Semitexa\Orm\Trait\HasTimestamps;
use Semitexa\Orm\Trait\HasUuidV7;
use Semitexa\Platform\Settings\Domain\Model\Setting;

#[FromTable(name: 'platform_settings', mapTo: Setting::class)]
#[TenantScoped(strategy: 'same_storage')]
#[Index(columns: ['tenant_id', 'module_key', 'key'], unique: true, name: 'uniq_platform_settings_tenant_module_key')]
class SettingResource implements DomainMappable
{
    use HasUuidV7;
    use HasTimestamps;

    /** Injected by tenant scope when tenancy is enabled; null for global settings. */
    #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
    public ?string $tenant_id = null;

    #[Column(type: MySqlType::Varchar, length: 128)]
    public string $module_key = '';

    #[Column(type: MySqlType::Varchar, length: 255)]
    public string $key = '';

    #[Column(type: MySqlType::LongText)]
    public string $value = '';

    public function toDomain(): Setting
    {
        return new Setting(
            id: $this->id,
            moduleKey: $this->module_key,
            key: $this->key,
            value: $this->value,
        );
    }

    public static function fromDomain(object $entity): static
    {
        assert($entity instanceof Setting);
        $r = new static();
        $r->id = $entity->id;
        $r->module_key = $entity->moduleKey;
        $r->key = $entity->key;
        $r->value = $entity->value;
        return $r;
    }
}
