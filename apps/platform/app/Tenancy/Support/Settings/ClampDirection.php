<?php

declare(strict_types=1);

namespace App\Tenancy\Support\Settings;

/**
 * Which way a firm is allowed to move a setting away from the platform value.
 *
 * `Banded` exists because "stricter" is not always one-directional. Retention
 * is the case: a shorter window is stricter for privacy, a longer one is
 * stricter for a statutory bookkeeping obligation. Those settings get an
 * explicit floor and ceiling instead of a comparison against the platform value.
 */
enum ClampDirection
{
    case LowerIsStricter;

    case HigherIsStricter;

    case Banded;
}
