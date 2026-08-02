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

export default function DuplicateRoleDialog({
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
                <DialogTitle>{t('Duplicate role')}</DialogTitle>
                <DialogDescription>
                    {t('Create a new role based on this role.')}
                </DialogDescription>
            </DialogHeader>
            {role ? (
                // Remount per role: the name input is uncontrolled, so its
                // defaultValue only refreshes when the subtree is recreated.
                <Form
                    key={role.id}
                    {...WorkspaceRoleController.store.form(currentWorkspace)}
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
                                    defaultValue={t(':name copy', {
                                        name: role.label,
                                    })}
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
                                    {t('Create role')}
                                </Button>
                            </DialogFooter>
                        </div>
                    )}
                </Form>
            ) : null}
        </Dialog>
    );
}
