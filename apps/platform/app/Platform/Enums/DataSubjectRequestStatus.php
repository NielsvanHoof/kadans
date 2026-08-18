<?php

declare(strict_types=1);

namespace App\Platform\Enums;

/**
 * A request is logged first and acted on second: the regulation allows a month
 * to respond, and erasure is not reversible.
 */
enum DataSubjectRequestStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Refused = 'refused';

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Awaiting action'),
            self::Completed => __('Completed'),
            self::Refused => __('Refused'),
        };
    }
}
