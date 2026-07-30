import { Head, setLayoutProps } from '@inertiajs/react';
import Heading from '@/components/heading';
import RoleMatrixCard from '@/features/roles/role-matrix-card';
import type {
    WorkspacePermissionGroup,
    WorkspaceRole,
} from '@/features/roles/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { index as rolesIndex } from '@/routes/workspaces/roles';

/**
 * Reference view of what each role in this firm is allowed to do.
 */
export default function WorkspaceRolesIndex({
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
        ],
    });

    return (
        <>
            <Head title={t('Roles & permissions')} />

            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-8">
                <Heading
                    level={1}
                    title={t('Roles & permissions')}
                    description={t(
                        'These are the standard roles. They are the same for every firm and cannot be changed here — assign a member a different role on the Members page.',
                    )}
                />

                <RoleMatrixCard
                    roles={roles}
                    permissionGroups={permissionGroups}
                />
            </div>
        </>
    );
}
