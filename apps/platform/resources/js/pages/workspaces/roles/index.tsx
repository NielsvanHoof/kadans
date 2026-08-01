import { Head, Link, setLayoutProps } from '@inertiajs/react';
import Heading from '@/components/primitives/heading';
import { Button } from '@/components/ui/button';
import RoleMatrixCard from '@/features/roles/role-matrix-card';
import RolesOverviewSummaryCard from '@/features/roles/roles-overview-summary-card';
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
 * Reference view of what each role in this firm is allowed to do.
 */
export default function WorkspaceRolesIndex({
    roles,
    permission_groups: permissionGroups,
    can_manage_roles: canManageRoles,
}: {
    roles: WorkspaceRole[];
    permission_groups: WorkspacePermissionGroup[];
    can_manage_roles: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Roles & permissions'),
                href: rolesIndex(currentWorkspace),
            },
        ],
    });

    return (
        <>
            <Head title={t('Roles & permissions')} />

            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        level={1}
                        className="mb-0"
                        title={t('Roles & permissions')}
                        description={t(
                            'Review which permissions each role grants in this workspace.',
                        )}
                    />
                    {canManageRoles ? (
                        <Button asChild>
                            <Link href={manageRoles(currentWorkspace)}>
                                {t('Manage custom roles')}
                            </Link>
                        </Button>
                    ) : null}
                </div>

                <RolesOverviewSummaryCard
                    roles={roles}
                    canManageRoles={canManageRoles}
                />
                <RoleMatrixCard
                    roles={roles}
                    permissionGroups={permissionGroups}
                />
            </div>
        </>
    );
}
