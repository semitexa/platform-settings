<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Platform\Settings\Application\Db\MySQL\Model\SettingResource;
use Semitexa\Platform\Settings\Domain\Model\Setting;

/** The bridge between the MySQL row and the setting the store reasons about. */
#[AsMapper(resourceModel: SettingResource::class, domainModel: Setting::class)]
final class SettingMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof SettingResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return new Setting(
            id: $resourceModel->id,
            tenantId: $resourceModel->tenant_id,
            userId: $resourceModel->user_id,
            moduleKey: $resourceModel->module_key,
            settingKey: $resourceModel->setting_key,
            value: $resourceModel->value,
            createdAt: $resourceModel->created_at,
            updatedAt: $resourceModel->updated_at,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof Setting || throw new \InvalidArgumentException('Unexpected domain model.');

        $now = new \DateTimeImmutable();

        return new SettingResource(
            id: $domainModel->getId(),
            tenant_id: $domainModel->getTenantId(),
            user_id: $domainModel->getUserId(),
            module_key: $domainModel->getModuleKey(),
            setting_key: $domainModel->getSettingKey(),
            value: $domainModel->getValue(),
            created_at: $domainModel->getCreatedAt() ?? $now,
            updated_at: $domainModel->getUpdatedAt() ?? $now,
        );
    }
}
