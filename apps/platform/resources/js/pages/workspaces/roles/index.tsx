import { Head, setLayoutProps } from '@inertiajs/react';
import Heading from '@/components/primitives/heading';
import { LinkButton } from '@/components/ui/button';
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
                        description={t('See what each role is allowed to do.')}
                    />
                    {canManageRoles ? (
                        <LinkButton href={manageRoles(currentWorkspace).url}>
                            {t('Manage custom roles')}
                        </LinkButton>
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
