<?php

declare(strict_types=1);

namespace App\Tenancy\Enums;

use App\Tenancy\Contracts\TenantPermission;

enum WorkspacePermission: string implements TenantPermission
{
    case ViewMembers = 'members.view';
    case InviteMembers = 'members.invite';
    case SuspendMembers = 'members.suspend';
    case RemoveMembers = 'members.remove';
    case AssignMemberRoles = 'members.assign_role';
    case ManageSettings = 'tenant.settings.manage';

    public function label(): string
    {
        return match ($this) {
            self::ViewMembers => __('View members'),
            self::InviteMembers => __('Invite members'),
            self::SuspendMembers => __('Suspend members'),
            self::RemoveMembers => __('Remove members'),
            self::AssignMemberRoles => __('Change member roles'),
            self::ManageSettings => __('Manage firm settings'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ViewMembers => __('See who belongs to the firm and their roles.'),
            self::InviteMembers => __('Send, resend, and revoke invitations to join the firm.'),
            self::SuspendMembers => __('Temporarily block a member from accessing the firm.'),
            self::RemoveMembers => __('Permanently remove a member from the firm.'),
            self::AssignMemberRoles => __('Change what a member is allowed to do. Grants privilege escalation.'),
            self::ManageSettings => __('Change firm-wide settings.'),
        };
    }

    public function group(): PermissionGroup
    {
        return match ($this) {
            self::ManageSettings => PermissionGroup::Settings,
            default => PermissionGroup::Members,
        };
    }

    public function defaultRoles(): array
    {
        return match ($this) {
            // Firm settings stay owner-only.
            self::ManageSettings => [],
            default => [Role::Administrator],
        };
    }
}
