<?php

declare(strict_types=1);

namespace App\Tenancy\Data\Members;

use App\Tenancy\Enums\TenantMembershipStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One workspace member row for the members Inertia page.
 */
#[TypeScript]
final class WorkspaceMemberData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $name,
        public readonly string $email,
        public readonly TenantMembershipStatus $status,
        public readonly ?string $role,
        public readonly ?string $roleLabel,
        public readonly ?string $joinedAt,
        public readonly ?string $lastAccessedAt,
        public readonly bool $isCurrentUser,
    ) {}
}
