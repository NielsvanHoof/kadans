<?php

declare(strict_types=1);

namespace App\Dossiers\Enums;

enum DocumentRequestStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    /**
     * @return list<self>
     */
    public static function awaitingClient(): array
    {
        return self::casesWhere(static fn (self $status): bool => $status->isAwaitingClient());
    }

    /**
     * @return list<string>
     */
    public static function awaitingClientValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::awaitingClient(),
        );
    }

    /**
     * The client still owes an answer or a replacement after rejection.
     */
    public function isAwaitingClient(): bool
    {
        return match ($this) {
            self::Pending, self::Rejected => true,
            self::Submitted, self::Accepted => false,
        };
    }

    /**
     * @param  callable(self): bool  $predicate
     * @return list<self>
     */
    private static function casesWhere(callable $predicate): array
    {
        $matches = [];

        foreach (self::cases() as $status) {
            if ($predicate($status)) {
                $matches[] = $status;
            }
        }

        return $matches;
    }
}
