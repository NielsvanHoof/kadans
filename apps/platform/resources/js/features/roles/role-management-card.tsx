import { Form } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import WorkspaceRoleController from '@/actions/App/Tenancy/Http/Controllers/WorkspaceRoleController';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import InputError from '@/components/primitives/input-error';
import { Button, LinkButton } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import DuplicateRoleDialog from '@/features/roles/duplicate-role-dialog';
import EditRoleDialog from '@/features/roles/edit-role-dialog';
import PermissionChecklist from '@/features/roles/permission-checklist';
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
 */
export default function RoleManagementCard({
    roles,
    permissionGroups,
}: RoleManagementCardProps) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const [editingRole, setEditingRole] = useState<WorkspaceRole | null>(null);
    const [duplicatingRole, setDuplicatingRole] =
        useState<WorkspaceRole | null>(null);
    const [newRoleName, setNewRoleName] = useState('');
    const [newRolePermissions, setNewRolePermissions] = useState<string[]>([]);
    const customRoles = useMemo(
        () => roles.filter((role) => !role.is_system),
        [roles],
    );
    const allPermissionValues = useMemo(
        () =>
            permissionGroups.flatMap((group) =>
                group.permissions.map((permission) => permission.value),
            ),
        [permissionGroups],
    );

    const selectAllCreatePermissions = () => {
        setNewRolePermissions(allPermissionValues);
    };

    const clearAllCreatePermissions = () => {
        setNewRolePermissions([]);
    };

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
                <Form
                    {...WorkspaceRoleController.store.form(currentWorkspace)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        setNewRoleName('');
                        setNewRolePermissions([]);
                    }}
                >
                    {({ processing, errors }) => (
                        <div className="space-y-4 rounded-lg border p-4">
                            <Field data-invalid={Boolean(errors.name)}>
                                <FieldLabel htmlFor="new-role-name">
                                    {t('Role name')}
                                </FieldLabel>
                                <Input
                                    id="new-role-name"
                                    name="name"
                                    value={newRoleName}
                                    onChange={(event) =>
                                        setNewRoleName(event.target.value)
                                    }
                                    required
                                    maxLength={255}
                                    placeholder={t('e.g. Operations manager')}
                                    aria-invalid={Boolean(errors.name)}
                                />
                                <InputError message={errors.name} />
                            </Field>
                            <PermissionChecklist
                                permissionGroups={permissionGroups}
                                selectedPermissions={newRolePermissions}
                                onSelectedPermissionsChange={
                                    setNewRolePermissions
                                }
                                onSelectAll={selectAllCreatePermissions}
                                onClearAll={clearAllCreatePermissions}
                            />
                            <InputError message={errors.permissions} />
                            {newRolePermissions.length === 0 ? (
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'Choose at least one permission before saving this role.',
                                    )}
                                </p>
                            ) : null}
                            <Button
                                type="submit"
                                isDisabled={
                                    processing ||
                                    newRolePermissions.length === 0
                                }
                            >
                                {t('Create role')}
                            </Button>
                        </div>
                    )}
                </Form>

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
                                    onClick={() => setDuplicatingRole(role)}
                                >
                                    {t('Duplicate :name', { name: role.label })}
                                </Button>
                            ))}
                        </div>
                    </div>

                    {customRoles.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
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
                                    <p className="text-xs text-muted-foreground">
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
                                        onClick={() => setDuplicatingRole(role)}
                                    >
                                        {t('Duplicate')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setEditingRole(role)}
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

            <EditRoleDialog
                role={editingRole}
                permissionGroups={permissionGroups}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditingRole(null);
                    }
                }}
            />
            <DuplicateRoleDialog
                role={duplicatingRole}
                permissionGroups={permissionGroups}
                onOpenChange={(open) => {
                    if (!open) {
                        setDuplicatingRole(null);
                    }
                }}
            />
        </Card>
    );
}
