<?php

declare(strict_types=1);

namespace App\Tenancy\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Full payload for the workspace members Inertia page.
 */
#[TypeScript]
final class WorkspaceMembersPageData extends Data
{
    /**
     * @param  list<WorkspaceMemberData>  $members
     * @param  list<WorkspaceInvitationData>  $invitations
     * @param  list<WorkspaceRoleOptionData>  $roleOptions
     */
    public function __construct(
        public readonly array $members,
        public readonly array $invitations,
        public readonly array $roleOptions,
        public readonly bool $canAssignOwner,
        public readonly ?string $roleFilter = null,
    ) {}
}
