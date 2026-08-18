<?php

declare(strict_types=1);

namespace App\Accounts\Enums;

/**
 * How strictly staff must hold a second authentication factor.
 *
 * Keyed by a permission *string* rather than a permission enum on purpose:
 * Accounts sits below Dossiers in the domain layering, so it must not import
 * `UploadedDocumentPermission`. The indirection also lets an operator re-key
 * enforcement onto a different permission without a deploy.
 */
enum TwoFactorEnforcement: string
{
    /** No requirement. Staff may enrol, nothing makes them. */
    case Off = 'off';

    /** Every staff account must hold a confirmed second factor. */
    case AllStaff = 'all';

    /**
     * Only staff whose role in the current workspace carries the configured
     * permission — by default, the ability to download client documents.
     */
    case ByPermission = 'by_permission';
}
