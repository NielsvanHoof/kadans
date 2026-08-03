import { Form } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import WorkspaceRoleController from '@/actions/App/Tenancy/Http/Controllers/WorkspaceRoleController';
import InputError from '@/components/primitives/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
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
    const [selectedPermissions, setSelectedPermissions] = useState<string[]>(
        [],
    );

    useEffect(() => {
        if (!role) {
            return;
        }

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
        <Dialog isOpen={role !== null} onOpenChange={onOpenChange}>
            <DialogHeader>
                <DialogTitle>{t('Edit role')}</DialogTitle>
                <DialogDescription>
                    {t('Update role name and permissions.')}
                </DialogDescription>
            </DialogHeader>
            {role ? (
                // Remount per role: the name input is uncontrolled, so its
                // defaultValue only refreshes when the subtree is recreated.
                <Form
                    key={role.id}
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
                                    defaultValue={role.label}
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
                                    setSelectedPermissions(allPermissionValues)
                                }
                                onClearAll={() => setSelectedPermissions([])}
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
                                <DialogClose type="button" variant="secondary">
                                    {t('Cancel')}
                                </DialogClose>
                                <Button
                                    type="submit"
                                    isDisabled={
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
        </Dialog>
    );
}
