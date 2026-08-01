<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

use App\Accounts\Models\User;
use App\Dossiers\Models\Dossier;
use App\Shared\Data\PersonOptionData;

/**
 * Builder edit-lock state for Inertia / JSON clients.
 */
final readonly class DossierBuilderLockData
{
    public function __construct(
        public bool $isMine,
        public ?string $token,
        public ?string $expiresAt,
        public ?PersonOptionData $holder,
    ) {}

    /**
     * Snapshot lock state for the given viewer (does not mutate the dossier).
     */
    public static function fromDossier(Dossier $dossier, ?User $viewer): self
    {
        if (! $dossier->hasActiveBuilderLock()) {
            return new self(
                isMine: false,
                token: null,
                expiresAt: null,
                holder: null,
            );
        }

        $holderUser = $dossier->builderLockedBy;
        $isMine = $viewer instanceof User && $dossier->isBuilderLockedBy($viewer);

        return new self(
            isMine: $isMine,
            // Only the holder receives the token (for heartbeat / release).
            token: $isMine ? $dossier->builder_lock_token : null,
            expiresAt: $dossier->builder_lock_expires_at?->toIso8601String(),
            holder: $holderUser instanceof User
                ? new PersonOptionData(
                    id: $holderUser->id,
                    name: $holderUser->name,
                    email: $holderUser->email,
                )
                : null,
        );
    }

    /**
     * @return array{
     *     is_mine: bool,
     *     token: string|null,
     *     expires_at: string|null,
     *     holder: array{id: int, name: string, email: string}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'is_mine' => $this->isMine,
            'token' => $this->token,
            'expires_at' => $this->expiresAt,
            'holder' => $this->holder?->toArray(),
        ];
    }
}
