<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\WmApp;

use Semitexa\Platform\Wm\Application\Attribute\AsWmApp;

#[AsWmApp(
    id: 'system-settings',
    title: 'System Settings',
    entryUrl: '/platform/settings',
    icon: '⚙',
)]
final class SystemSettingsWmApp {}
