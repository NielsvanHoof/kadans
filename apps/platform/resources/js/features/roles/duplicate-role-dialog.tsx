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
