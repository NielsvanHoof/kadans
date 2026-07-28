<?php

declare(strict_types=1);

namespace App\Accounts\Enums;

/**
 * Global platform roles (not tenant-scoped).
 * Separate from firm Role::Administrator.
 */
enum PlatformRole: string
{
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::Support => __('Support'),
        };
    }
}
