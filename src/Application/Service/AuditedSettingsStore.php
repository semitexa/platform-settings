<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\Application\Service\OrmBackedStore;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Platform\Settings\Application\Db\MySQL\Model\SettingResource;
use Semitexa\Platform\Settings\Domain\Contract\AuditedSettingsStoreInterface;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;
use Semitexa\Platform\Settings\Domain\Model\Setting;
use Semitexa\Platform\Settings\Domain\Model\SettingRecord;

/**
 * Writes through {@see SettingsStoreInterface} — its validation, its race-safe
 * first write and its cache invalidation — and then names the author on the row
 * it wrote, in the same transaction. Kept apart from SettingsStore, which is a
 * recorded structural outlier at its size budget.
 */
#[SatisfiesServiceContract(of: AuditedSettingsStoreInterface::class)]
final class AuditedSettingsStore implements AuditedSettingsStoreInterface
{
    use OrmBackedStore;

    /** The `updated_by` column's length. */
    private const AUTHOR_MAX = 64;

    #[InjectAsReadonly]
    protected OrmManager $orm;

    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    public function setBy(string $moduleKey, string $key, mixed $value, ?string $byUserId): void
    {
        $this->setManyBy($moduleKey, [$key => $value], $byUserId);
    }

    public function setManyBy(string $moduleKey, array $values, ?string $byUserId): void
    {
        if ($byUserId !== null && ($byUserId === '' || strlen($byUserId) > self::AUTHOR_MAX)) {
            throw new \InvalidArgumentException('byUserId must be null or 1-' . self::AUTHOR_MAX . ' characters');
        }
        $this->orm()->getTransactionManager()->run(function () use ($moduleKey, $values, $byUserId): void {
            foreach ($values as $key => $value) {
                $key = (string) $key;
                $this->settings->set($moduleKey, $key, $value);
                if ($byUserId !== null) {
                    $this->orm()->getQueryAdapter()->execute(
                        'UPDATE `platform_settings` SET updated_by = :updated_by
                          WHERE tenant_id = :tenant_id AND module_key = :module_key
                            AND setting_key = :setting_key AND user_id IS NULL',
                        [
                            'updated_by' => $byUserId,
                            'tenant_id' => $this->currentTenantId(),
                            'module_key' => $moduleKey,
                            'setting_key' => $key,
                        ],
                    );
                }
            }
        });
    }

    public function describe(string $moduleKey, string $key): ?SettingRecord
    {
        // A fresh read: an admin page asks once, and the store's request cache
        // holds the value, not necessarily the row as it is now.
        /** @var Setting|null $row */
        $row = $this->domainRepository(SettingResource::class, Setting::class)
            ->forTenant($this->currentTenantId())
            ->query()
            ->where(SettingResource::column('module_key'), Operator::Equals, $moduleKey)
            ->where(SettingResource::column('setting_key'), Operator::Equals, $key)
            ->whereNull(SettingResource::column('user_id'))
            ->fetchOneAs(Setting::class, $this->mapperRegistry());
        if ($row === null) {
            return null;
        }

        return new SettingRecord(
            value: $row->isBlank() ? null : json_decode($row->getValue(), true, 512, \JSON_THROW_ON_ERROR),
            updatedAt: $row->getUpdatedAt() ?? new \DateTimeImmutable('@0'),
            updatedBy: $row->getUpdatedBy(),
        );
    }

    /** The same tenant id SettingsStore writes under: the ambient one, or 'default'. */
    private function currentTenantId(): string
    {
        $context = isset($this->tenantContextStore) ? $this->tenantContextStore->tryGet() : null;

        return TenantContextAccess::tenantIdOrDefault($context);
    }
}
