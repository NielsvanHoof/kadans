<?php

declare(strict_types=1);

namespace App\Dossiers\Enums;

enum DossierStatus: string
{
    case Draft = 'draft';
    case AwaitingClient = 'awaiting_client';
    case InReview = 'in_review';
    case Completed = 'completed';

    /**
     * @return list<string>
     */
    public static function lockedValues(): array
    {
        $values = [];

        foreach (self::cases() as $status) {
            if ($status->isLocked()) {
                $values[] = $status->value;
            }
        }

        return $values;
    }

    /**
     * Staff mutations and client reminders stop once the dossier is locked.
     */
    public function isLocked(): bool
    {
        return match ($this) {
            self::Completed => true,
            self::Draft, self::AwaitingClient, self::InReview => false,
        };
    }
}
