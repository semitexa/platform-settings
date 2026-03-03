<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Domain\Model;

final readonly class Setting
{
    public function __construct(
        public string $id,
        public string $moduleKey,
        public string $key,
        public string $value,
    ) {
    }
}
