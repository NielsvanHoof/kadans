import { Form, Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import WorkspaceRoleController from '@/actions/App/Tenancy/Http/Controllers/WorkspaceRoleController';
import ConfirmDestroyDialog from '@/components/confirm-destroy-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
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
                                disabled={
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
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        asChild
                                    >
                                        <Link
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
                                        </Link>
                                    </Button>
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

function PermissionChecklist({
    permissionGroups,
    selectedPermissions,
    onSelectedPermissionsChange,
    onSelectAll,
    onClearAll,
}: {
    permissionGroups: WorkspacePermissionGroup[];
    selectedPermissions: string[];
    onSelectedPermissionsChange: (permissions: string[]) => void;
    onSelectAll: () => void;
    onClearAll: () => void;
}) {
    const { t } = useTranslation();
    const selectedPermissionSet = useMemo(
        () => new Set(selectedPermissions),
        [selectedPermissions],
    );

    const togglePermission = (permissionValue: string, selected: boolean) => {
        const nextPermissions = new Set(selectedPermissionSet);

        if (selected) {
            nextPermissions.add(permissionValue);
        } else {
            nextPermissions.delete(permissionValue);
        }

        onSelectedPermissionsChange([...nextPermissions]);
    };

    const toggleGroupPermissions = (
        group: WorkspacePermissionGroup,
        selected: boolean,
    ) => {
        const nextPermissions = new Set(selectedPermissionSet);

        for (const permission of group.permissions) {
            if (selected) {
                nextPermissions.add(permission.value);
            } else {
                nextPermissions.delete(permission.value);
            }
        }

        onSelectedPermissionsChange([...nextPermissions]);
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onSelectAll}
                >
                    {t('Select all')}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onClearAll}
                >
                    {t('Clear all')}
                </Button>
            </div>
            {permissionGroups.map((group) => (
                <div key={group.value} className="space-y-2">
                    <div className="flex items-center justify-between gap-2">
                        <h4 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {group.label}
                        </h4>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    toggleGroupPermissions(group, true)
                                }
                            >
                                {t('All')}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    toggleGroupPermissions(group, false)
                                }
                            >
                                {t('None')}
                            </Button>
                        </div>
                    </div>
                    <div className="space-y-2">
                        {group.permissions.map((permission) => {
                            const checkboxId = `permission-${group.value}-${permission.value}`;

                            return (
                                <div
                                    key={permission.value}
                                    className="flex items-start gap-2"
                                >
                                    <Checkbox
                                        id={checkboxId}
                                        name="permissions[]"
                                        value={permission.value}
                                        checked={selectedPermissionSet.has(
                                            permission.value,
                                        )}
                                        onCheckedChange={(checked) =>
                                            togglePermission(
                                                permission.value,
                                                checked === true,
                                            )
                                        }
                                    />
                                    <FieldLabel
                                        htmlFor={checkboxId}
                                        className="cursor-pointer text-sm"
                                    >
                                        <span className="block font-medium">
                                            {permission.label}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {permission.description}
                                        </span>
                                    </FieldLabel>
                                </div>
                            );
                        })}
                    </div>
                </div>
            ))}
        </div>
    );
}

function EditRoleDialog({
    role,
    permissionGroups,
    onOpenChange,
}: {
    role: WorkspaceRole | null;
    permissionGroups: WorkspacePermissionGroup[];
    onOpenChange: (open: boolean) => void;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const allPermissionValues = useMemo(
        () =>
            permissionGroups.flatMap((group) =>
                group.permissions.map((permission) => permission.value),
            ),
        [permissionGroups],
    );
    const [roleName, setRoleName] = useState('');
    const [selectedPermissions, setSelectedPermissions] = useState<string[]>(
        [],
    );

    useEffect(() => {
        if (!role) {
            return;
        }

        setRoleName(role.label);
        setSelectedPermissions(
            permissionGroups
                .flatMap((group) => group.permissions)
                .filter((permission) =>
                    permission.granted_to.includes(role.value),
                )
                .map((permission) => permission.value),
        );
    }, [permissionGroups, role]);

    return (
        <Dialog open={role !== null} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('Edit role')}</DialogTitle>
                    <DialogDescription>
                        {t('Update role name and permissions.')}
                    </DialogDescription>
                </DialogHeader>
                {role ? (
                    <Form
                        {...WorkspaceRoleController.update.form({
                            tenant: currentWorkspace,
                            role: role.id,
                        })}
                        options={{ preserveScroll: true }}
                        onSuccess={() => onOpenChange(false)}
                    >
                        {({ processing, errors }) => (
                            <div className="space-y-4">
                                <Field data-invalid={Boolean(errors.name)}>
                                    <FieldLabel htmlFor="edit-role-name">
                                        {t('Role name')}
                                    </FieldLabel>
                                    <Input
                                        id="edit-role-name"
                                        name="name"
                                        value={roleName}
                                        onChange={(event) =>
                                            setRoleName(event.target.value)
                                        }
                                        required
                                        maxLength={255}
                                    />
                                    <InputError message={errors.name} />
                                </Field>
                                <PermissionChecklist
                                    permissionGroups={permissionGroups}
                                    selectedPermissions={selectedPermissions}
                                    onSelectedPermissionsChange={
                                        setSelectedPermissions
                                    }
                                    onSelectAll={() =>
                                        setSelectedPermissions(
                                            allPermissionValues,
                                        )
                                    }
                                    onClearAll={() =>
                                        setSelectedPermissions([])
                                    }
                                />
                                <InputError message={errors.permissions} />
                                {selectedPermissions.length === 0 ? (
                                    <p className="text-xs text-muted-foreground">
                                        {t(
                                            'Choose at least one permission before saving this role.',
                                        )}
                                    </p>
                                ) : null}
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                        >
                                            {t('Cancel')}
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing ||
                                            selectedPermissions.length === 0
                                        }
                                    >
                                        {t('Save role')}
                                    </Button>
                                </DialogFooter>
                            </div>
                        )}
                    </Form>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function DuplicateRoleDialog({
    role,
    permissionGroups,
    onOpenChange,
}: {
    role: WorkspaceRole | null;
    permissionGroups: WorkspacePermissionGroup[];
    onOpenChange: (open: boolean) => void;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const [name, setName] = useState('');
    const [selectedPermissions, setSelectedPermissions] = useState<string[]>(
        [],
    );
    const allPermissionValues = useMemo(
        () =>
            permissionGroups.flatMap((group) =>
                group.permissions.map((permission) => permission.value),
            ),
        [permissionGroups],
    );

    useEffect(() => {
        if (!role) {
            return;
        }

        setName(t(':name copy', { name: role.label }));
        setSelectedPermissions(
            permissionGroups
                .flatMap((group) => group.permissions)
                .filter((permission) =>
                    permission.granted_to.includes(role.value),
                )
                .map((permission) => permission.value),
        );
    }, [permissionGroups, role, t]);

    return (
        <Dialog open={role !== null} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('Duplicate role')}</DialogTitle>
                    <DialogDescription>
                        {t('Create a new role based on this role.')}
                    </DialogDescription>
                </DialogHeader>
                {role ? (
                    <Form
                        {...WorkspaceRoleController.store.form(
                            currentWorkspace,
                        )}
                        options={{ preserveScroll: true }}
                        onSuccess={() => onOpenChange(false)}
                    >
                        {({ processing, errors }) => (
                            <div className="space-y-4">
                                <Field data-invalid={Boolean(errors.name)}>
                                    <FieldLabel htmlFor="duplicate-role-name">
                                        {t('Role name')}
                                    </FieldLabel>
                                    <Input
                                        id="duplicate-role-name"
                                        name="name"
                                        value={name}
                                        onChange={(event) =>
                                            setName(event.target.value)
                                        }
                                        required
                                        maxLength={255}
                                    />
                                    <InputError message={errors.name} />
                                </Field>
                                <PermissionChecklist
                                    permissionGroups={permissionGroups}
                                    selectedPermissions={selectedPermissions}
                                    onSelectedPermissionsChange={
                                        setSelectedPermissions
                                    }
                                    onSelectAll={() =>
                                        setSelectedPermissions(
                                            allPermissionValues,
                                        )
                                    }
                                    onClearAll={() =>
                                        setSelectedPermissions([])
                                    }
                                />
                                <InputError message={errors.permissions} />
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                        >
                                            {t('Cancel')}
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing ||
                                            selectedPermissions.length === 0
                                        }
                                    >
                                        {t('Create role')}
                                    </Button>
                                </DialogFooter>
                            </div>
                        )}
                    </Form>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
