<?php

declare(strict_types=1);

namespace App\Tenancy\Data\Invitations;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One pending invitation row for the members Inertia page.
 */
#[TypeScript]
final class WorkspaceInvitationData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $role,
        public readonly string $roleLabel,
        public readonly string $invitedAt,
        public readonly string $expiresAt,
    ) {}
}
