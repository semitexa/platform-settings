<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Update;

use Semitexa\Update\Attribute\AsDataPatch;
use Semitexa\Update\Context\DataPatchContext;
use Semitexa\Update\Domain\Contract\DataPatchInterface;
use Semitexa\Update\Domain\Enum\UpdatePhase;

/**
 * Backfill platform_settings.tenant_id after the store became #[TenantScoped].
 *
 * The column pre-existed but the old store INSERTed literal NULL and never
 * filtered by it, so every existing row is NULL. The scoped store now reads
 * and writes under forTenant('default') (WHERE tenant_id = 'default') — without
 * this patch every setting (assistant name, theme/skin, weave cursor) silently
 * "resets" and the NULL rows become permanently unreachable (fail-closed reads,
 * tenant-filtered deletes). Idempotent: only NULL rows.
 */
#[AsDataPatch(
    id: 'backfill-settings-tenant-id',
    module: 'semitexa/platform-settings',
    phase: UpdatePhase::Post,
    requiresColumns: ['platform_settings' => ['tenant_id']],
    description: 'Assign existing platform settings to the default tenant.',
)]
final class BackfillSettingsTenantId implements DataPatchInterface
{
    public function apply(DataPatchContext $ctx): void
    {
        $ctx->execute("UPDATE `platform_settings` SET `tenant_id` = 'default' WHERE `tenant_id` IS NULL");
    }
}
