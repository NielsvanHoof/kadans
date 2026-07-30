import { Head, Link, setLayoutProps } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import RoleManagementCard from '@/features/roles/role-management-card';
import type {
    WorkspacePermissionGroup,
    WorkspaceRole,
} from '@/features/roles/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import {
    manage as manageRoles,
    index as rolesIndex,
} from '@/routes/workspaces/roles';

/**
 * Dedicated workspace role management page.
 */
export default function WorkspaceRolesManage({
    roles,
    permission_groups: permissionGroups,
}: {
    roles: WorkspaceRole[];
    permission_groups: WorkspacePermissionGroup[];
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Roles & permissions'),
                href: rolesIndex(currentWorkspace),
            },
            {
                title: t('Manage custom roles'),
                href: manageRoles(currentWorkspace),
            },
        ],
    });

    return (
        <>
            <Head title={t('Manage custom roles')} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        level={1}
                        className="mb-0"
                        title={t('Manage custom roles')}
                        description={t(
                            'Create, update, and delete tenant-specific roles and permissions.',
                        )}
                    />
                    <Button variant="outline" asChild>
                        <Link href={rolesIndex(currentWorkspace)}>
                            {t('Back to overview')}
                        </Link>
                    </Button>
                </div>
                <RoleManagementCard
                    roles={roles}
                    permissionGroups={permissionGroups}
                />
            </div>
        </>
    );
}
