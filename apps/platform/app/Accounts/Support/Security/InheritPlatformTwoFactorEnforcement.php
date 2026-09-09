<?php

declare(strict_types=1);

namespace App\Accounts\Support\Security;

use App\Accounts\Contracts\TwoFactorEnforcementSource;
use App\Accounts\Enums\TwoFactorEnforcement;
use Override;

/**
 * Asks nothing beyond the platform setting.
 *
 * The default binding, so Accounts stands up on its own — in a test that boots
 * no higher domain, and on any future route group with no firm behind it. A
 * null object rather than a null check in the middleware, so there is one path
 * through enforcement instead of two.
 */
final readonly class InheritPlatformTwoFactorEnforcement implements TwoFactorEnforcementSource
{
    #[Override]
    public function forCurrentContext(): ?TwoFactorEnforcement
    {
        return null;
    }
}
