<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\App;

use Semitexa\Platform\Wm\Attributes\AsWmApp;

#[AsWmApp(
    id: 'system-settings',
    title: 'System Settings',
    entryUrl: '/platform/settings',
    icon: '⚙',
)]
final class SystemSettingsWmApp
{
}
