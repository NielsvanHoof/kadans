<?php

declare(strict_types=1);

namespace App\Accounts\Contracts;

use App\Accounts\Enums\TwoFactorEnforcement;

/**
 * What the current context asks of staff, on top of the platform setting.
 *
 * Accounts sits below Tenancy in the domain layering, so the middleware that
 * enforces a second factor cannot read a firm's settings directly. Accounts
 * owns this contract and Tenancy implements it — the same inversion, in the
 * same direction, as `Tenancy\Contracts\SupportSessionState`, which Platform
 * implements for Tenancy.
 *
 * Deliberately takes no tenant argument: naming that type is precisely what
 * this domain is not allowed to do. An implementation decides for itself what
 * "current" means.
 *
 * Returning null means "nothing further is asked here", not "off". An
 * implementation cannot lower enforcement: the caller clamps the answer against
 * the platform floor, so the worst a wrong implementation can do is fail to
 * tighten.
 */
interface TwoFactorEnforcementSource
{
    public function forCurrentContext(): ?TwoFactorEnforcement;
}
