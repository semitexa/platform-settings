<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Domain\Model;

/** A setting's value as stored, with when it last changed and who changed it. */
final readonly class SettingRecord
{
    public function __construct(
        /** The decoded value, as SettingsStoreInterface::get() returns it. */
        public mixed $value,
        public \DateTimeImmutable $updatedAt,
        /** Null when the last write named nobody. */
        public ?string $updatedBy,
    ) {}
}
