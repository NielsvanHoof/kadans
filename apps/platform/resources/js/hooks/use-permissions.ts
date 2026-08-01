import { usePage } from '@inertiajs/react';
import type { TenantPermission } from '@/types/generated/tenant-permissions';

/**
 * Gate UI on a tenant permission resolved for the current firm.
 *
 * The permission name is a closed union generated from PermissionRegistry, so a
 * typo fails type checking rather than silently hiding the element. Outside a
 * firm context the shared prop is empty and every check is false.
 */
export function usePermissions() {
    const permissions = usePage().props.permissions;

    return {
        can: (permission: TenantPermission): boolean =>
            permissions[permission] === true,
    };
}
