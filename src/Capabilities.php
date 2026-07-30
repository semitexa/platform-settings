<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Without this the package is invisible to anyone whose project has not
 * installed it - which is precisely the audience worth telling, since they are
 * the ones about to build it by hand. The convention is one `Capabilities` class
 * per package: a definite place to look, and a definite place for a guard to
 * check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'settings.store',
    summary: 'A settings store for modules, scoped per tenant and per user.',
    useWhen: 'A module needs values an operator can change at runtime, remembered per tenant or per user.',
    avoidWhen: 'The value is fixed at deploy time - environment configuration is simpler and reviewable.',
    replaces: [
        'a settings table plus accessor written per module',
        'configuration keys read from the environment and then overridden in the database anyway',
    ],
)]
final class Capabilities
{
}
