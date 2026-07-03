<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Platform\Settings\Application\Db\MySQL\Model\SettingResource;

/**
 * Self-mapping mapper for {@see SettingResource}.
 *
 * The settings row lines up 1:1 with the store's needs, so there is no separate
 * mutable domain model — resource IS the domain model and both directions are
 * clone-passthroughs (the same trivial-shape convention as the platform-ui and
 * scheduler resources).
 */
#[AsMapper(
    resourceModel: SettingResource::class,
    domainModel: SettingResource::class,
)]
final class SettingMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof SettingResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        return clone $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof SettingResource
            || throw new \InvalidArgumentException('Unexpected domain model.');

        return clone $domainModel;
    }
}
