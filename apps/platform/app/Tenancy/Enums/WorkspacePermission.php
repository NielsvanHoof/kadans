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
    case ViewRoles = 'roles.view';
    case ManageRoles = 'roles.manage';
    case ManageSettings = 'tenant.settings.manage';
    case AccessAdmin = 'tenant.admin.access';

    public function label(): string
    {
        return match ($this) {
            self::ViewMembers => __('View members'),
            self::InviteMembers => __('Invite members'),
            self::SuspendMembers => __('Suspend members'),
            self::RemoveMembers => __('Remove members'),
            self::AssignMemberRoles => __('Change member roles'),
            self::ViewRoles => __('View roles'),
            self::ManageRoles => __('Manage roles'),
            self::ManageSettings => __('Manage firm settings'),
            self::AccessAdmin => __('Access the admin area'),
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
            self::ViewRoles => __('See which permissions each role grants.'),
            self::ManageRoles => __('Change what each role is allowed to do.'),
            self::ManageSettings => __('Change firm-wide settings.'),
            self::AccessAdmin => __('Open the firm administration area.'),
        };
    }

    public function group(): PermissionGroup
    {
        return match ($this) {
            self::ManageSettings, self::AccessAdmin => PermissionGroup::Settings,
            default => PermissionGroup::Members,
        };
    }

    public function defaultRoles(): array
    {
        return match ($this) {
            // Reshaping roles stays owner-only; firm settings is an administrator job.
            self::ManageRoles => [],
            default => [Role::Administrator],
        };
    }
}
