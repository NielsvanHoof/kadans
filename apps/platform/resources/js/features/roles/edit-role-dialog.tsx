import { Form } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import WorkspaceRoleController from '@/actions/App/Tenancy/Http/Controllers/WorkspaceRoleController';
import InputError from '@/components/primitives/input-error';
import { Button } from '@/components/ui/button';
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
import PermissionChecklist from '@/features/roles/permission-checklist';
import type {
    WorkspacePermissionGroup,
    WorkspaceRole,
} from '@/features/roles/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';

export default function EditRoleDialog({
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
