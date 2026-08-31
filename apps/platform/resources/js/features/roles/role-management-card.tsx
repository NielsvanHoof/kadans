import { useMemo, useState } from 'react';
import WorkspaceRoleController from '@/actions/App/Tenancy/Http/Controllers/WorkspaceRoleController';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import { Button, LinkButton } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import RoleFormDialog, {
    type RoleFormDialogState,
} from '@/features/roles/role-form-dialog';
import type {
    WorkspacePermissionGroup,
    WorkspaceRole,
} from '@/features/roles/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { index as membersIndex } from '@/routes/workspaces/members';

type RoleManagementCardProps = {
    roles: WorkspaceRole[];
    permissionGroups: WorkspacePermissionGroup[];
};

/**
 * Manages tenant-authored roles. System roles stay read-only.
 * Create / edit / duplicate all go through RoleFormDialog.
 */
export default function RoleManagementCard({
    roles,
    permissionGroups,
}: RoleManagementCardProps) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const [dialog, setDialog] = useState<RoleFormDialogState | null>(null);
    const customRoles = useMemo(
        () => roles.filter((role) => !role.is_system),
        [roles],
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Custom roles')}</CardTitle>
                <CardDescription>
                    {t(
                        'Create custom roles for your workspace and choose exactly which permissions they include.',
                    )}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
                <Button
                    type="button"
                    onClick={() => setDialog({ mode: 'create' })}
                >
                    {t('Create role')}
                </Button>

                <div className="space-y-3">
                    <div className="space-y-2">
                        <p className="text-sm font-medium">
                            {t('Start from an existing role')}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {roles.map((role) => (
                                <Button
                                    key={`duplicate-${role.value}`}
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setDialog({
                                            mode: 'duplicate',
                                            role,
                                        })
                                    }
                                >
                                    {t('Duplicate :name', { name: role.label })}
                                </Button>
                            ))}
                        </div>
                    </div>

                    {customRoles.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('No custom roles yet.')}
                        </p>
                    ) : (
                        customRoles.map((role) => (
                            <div
                                key={role.value}
                                className="flex items-center justify-between rounded-md border px-3 py-2"
                            >
                                <div>
                                    <p className="font-medium">{role.label}</p>
                                    <p className="text-muted-foreground text-xs">
                                        {role.member_count === 1
                                            ? t('1 member')
                                            : t(':count members', {
                                                  count: role.member_count,
                                              })}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <LinkButton
                                        variant="outline"
                                        size="sm"
                                        href={membersIndex.url(
                                            currentWorkspace,
                                            {
                                                query: {
                                                    role: role.value,
                                                },
                                            },
                                        )}
                                    >
                                        {t('View members')}
                                    </LinkButton>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setDialog({
                                                mode: 'duplicate',
                                                role,
                                            })
                                        }
                                    >
                                        {t('Duplicate')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setDialog({
                                                mode: 'edit',
                                                role,
                                            })
                                        }
                                    >
                                        {t('Edit')}
                                    </Button>
                                    <ConfirmDestroyDialog
                                        title={t('Delete role?')}
                                        description={t(
                                            'Delete :name? Members with this role must be reassigned first.',
                                            { name: role.label },
                                        )}
                                        confirmLabel={t('Delete')}
                                        form={WorkspaceRoleController.destroy.form(
                                            {
                                                tenant: currentWorkspace,
                                                role: role.id,
                                            },
                                        )}
                                        options={{ preserveScroll: true }}
                                        trigger={
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                            >
                                                {t('Delete')}
                                            </Button>
                                        }
                                    />
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </CardContent>

            <RoleFormDialog
                state={dialog}
                permissionGroups={permissionGroups}
                onOpenChange={(open) => {
                    if (!open) {
                        setDialog(null);
                    }
                }}
            />
        </Card>
    );
}
