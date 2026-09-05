<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Domain\Model;

/**
 * One stored setting, scoped to a module and — optionally — to a tenant and a
 * user.
 *
 * The three-part scope is the meaning here, not the storage: a value with a
 * user is that person's, a value with only a tenant is the site's, and one with
 * neither is the install's. Which row wins is a decision this package makes;
 * that MySQL keeps the value as a JSON string in a longtext column is not.
 */
final readonly class Setting
{
    public function __construct(
        private string $id,
        private ?string $tenantId,
        private ?string $userId,
        private string $moduleKey,
        private string $settingKey,
        /** The raw stored value — JSON, decoded by the store that asked for it. */
        private string $value,
        private ?\DateTimeImmutable $createdAt = null,
        private ?\DateTimeImmutable $updatedAt = null,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getModuleKey(): string
    {
        return $this->moduleKey;
    }

    public function getSettingKey(): string
    {
        return $this->settingKey;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** True when nothing was stored — distinct from a stored empty value. */
    public function isBlank(): bool
    {
        return $this->value === '';
    }

    /** Belongs to one person rather than to the whole site. */
    public function isPersonal(): bool
    {
        return $this->userId !== null && $this->userId !== '';
    }
}
