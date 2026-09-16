<?php

declare(strict_types=1);

namespace App\Dossiers\Enums;

use function array_map;

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
        return self::valuesWhere(static fn (self $status): bool => $status->isLocked());
    }

    /**
     * @return list<self>
     */
    public static function sharedWithClient(): array
    {
        return self::casesWhere(static fn (self $status): bool => $status->isSharedWithClient());
    }

    /**
     * @return list<string>
     */
    public static function sharedWithClientValues(): array
    {
        return self::valuesWhere(static fn (self $status): bool => $status->isSharedWithClient());
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

    /**
     * The firm has put this dossier in front of the client.
     *
     * This is what a portal grant may reach, and Draft is the whole boundary:
     * a dossier is invisible until a member with `client_access.grant` shares
     * it, and nothing ever moves one back to Draft (RollOverDossierAction sets
     * it only on a row it just created), so the boundary cannot be re-crossed.
     *
     * Completed stays reachable on purpose. Write protection is
     * DocumentRequestTransitions::canSubmit()'s job, not reachability's — and
     * dropping a dossier out of reach the moment staff complete it would sign
     * the client out mid-questionnaire, which is exactly the failure the
     * sliding session window exists to prevent.
     */
    public function isSharedWithClient(): bool
    {
        return match ($this) {
            self::AwaitingClient, self::InReview, self::Completed => true,
            self::Draft => false,
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

    /**
     * @param  callable(self): bool  $predicate
     * @return list<string>
     */
    private static function valuesWhere(callable $predicate): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::casesWhere($predicate),
        );
    }
}
