<?php

declare(strict_types=1);

namespace App\Tenancy\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Adviser = 'adviser';
    case Reviewer = 'reviewer';
    case ReadOnly = 'read-only';

    /**
     * Roles that can be assigned via invite / role change forms.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [
            self::Administrator,
            self::Adviser,
            self::Reviewer,
            self::ReadOnly,
            self::Owner,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Administrator => __('Administrator'),
            self::Adviser => __('Adviser'),
            self::Reviewer => __('Reviewer'),
            self::ReadOnly => __('Read-only'),
        };
    }

    /**
     * Owners implicitly hold every registered permission.
     */
    public function grantsAllPermissions(): bool
    {
        return $this === self::Owner;
    }
}
