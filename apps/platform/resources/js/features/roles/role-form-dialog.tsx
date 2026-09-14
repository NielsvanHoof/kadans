import { Form } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import WorkspaceRoleController from '@/actions/App/Tenancy/Http/Controllers/Roles/WorkspaceRoleController';
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

export type RoleFormMode = 'create' | 'edit' | 'duplicate';

export type RoleFormDialogState =
    | { mode: 'create' }
    | { mode: 'edit'; role: WorkspaceRole }
    | { mode: 'duplicate'; role: WorkspaceRole };

type RoleFormDialogProps = {
    state: RoleFormDialogState | null;
    permissionGroups: WorkspacePermissionGroup[];
    onOpenChange: (open: boolean) => void;
};

/**
 * Shared create / edit / duplicate role dialog.
 * One form shape so validation guidance cannot drift between modes.
 */
export default function RoleFormDialog({
    state,
    permissionGroups,
    onOpenChange,
}: RoleFormDialogProps) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const mode = state?.mode ?? null;
    const role = state && state.mode !== 'create' ? state.role : null;

    const copy = modeCopy(mode, t);
    const formProps = formPropsForMode(mode, role, currentWorkspace);
    // Remount when mode/role changes so uncontrolled name defaultValue and
    // permission selection refresh without a props→state effect.
    const formKey =
        mode === 'create'
            ? 'create'
            : mode && role
              ? `${mode}-${role.id}`
              : 'closed';

    return (
        <Dialog
            isOpen={state !== null}
            onOpenChange={onOpenChange}
            className="sm:max-w-4xl"
        >
            <DialogHeader>
                <DialogTitle>{copy.title}</DialogTitle>
                <DialogDescription>{copy.description}</DialogDescription>
            </DialogHeader>
            {state && formProps ? (
                <RoleFormFields
                    key={formKey}
                    state={state}
                    permissionGroups={permissionGroups}
                    formProps={formProps}
                    submitLabel={copy.submit}
                    onSuccess={() => onOpenChange(false)}
                />
            ) : null}
        </Dialog>
    );
}

function RoleFormFields({
    state,
    permissionGroups,
    formProps,
    submitLabel,
    onSuccess,
}: {
    state: RoleFormDialogState;
    permissionGroups: WorkspacePermissionGroup[];
    formProps: NonNullable<ReturnType<typeof formPropsForMode>>;
    submitLabel: string;
    onSuccess: () => void;
}) {
    const { t } = useTranslation();
    const mode = state.mode;

    const allPermissionValues = useMemo(
        () =>
            permissionGroups.flatMap((group) =>
                group.permissions.map((permission) => permission.value),
            ),
        [permissionGroups],
    );

    // Initialized once per formKey remount — no effect sync from props.
    const [selectedPermissions, setSelectedPermissions] = useState<string[]>(
        () => initialPermissionsForState(state, permissionGroups),
    );

    return (
        <Form
            {...formProps}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
        >
            {({ processing, errors }) => (
                <div className="space-y-4">
                    <Field data-invalid={Boolean(errors.name)}>
                        <FieldLabel htmlFor="role-form-name">
                            {t('Role name')}
                        </FieldLabel>
                        <Input
                            id="role-form-name"
                            name="name"
                            defaultValue={defaultNameForMode(state, t)}
                            required
                            maxLength={255}
                            placeholder={
                                mode === 'create'
                                    ? t('e.g. Operations manager')
                                    : undefined
                            }
                        />
                        <InputError message={errors.name} />
                    </Field>

                    {/*
                      The payload is written here rather than by the checkboxes:
                      searching or collapsing unmounts a checked permission, and
                      an unmounted input posts nothing.
                    */}
                    {selectedPermissions.map((permission) => (
                        <input
                            key={permission}
                            type="hidden"
                            name="permissions[]"
                            value={permission}
                        />
                    ))}

                    <div className="max-h-[55vh] overflow-y-auto overscroll-contain pr-1">
                        <PermissionChecklist
                            permissionGroups={permissionGroups}
                            selectedPermissions={selectedPermissions}
                            onSelectedPermissionsChange={setSelectedPermissions}
                            onSelectAll={() =>
                                setSelectedPermissions(allPermissionValues)
                            }
                            onClearAll={() => setSelectedPermissions([])}
                        />
                    </div>
                    <InputError message={errors.permissions} />
                    {selectedPermissions.length === 0 ? (
                        <p className="text-muted-foreground text-xs">
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
                                processing || selectedPermissions.length === 0
                            }
                        >
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </div>
            )}
        </Form>
    );
}

function initialPermissionsForState(
    state: RoleFormDialogState,
    permissionGroups: WorkspacePermissionGroup[],
): string[] {
    if (state.mode === 'create') {
        return [];
    }

    return permissionGroups
        .flatMap((group) => group.permissions)
        .filter((permission) =>
            permission.granted_to.includes(state.role.value),
        )
        .map((permission) => permission.value);
}

function modeCopy(
    mode: RoleFormMode | null,
    t: (key: string) => string,
): { title: string; description: string; submit: string } {
    switch (mode) {
        case 'edit':
            return {
                title: t('Edit role'),
                description: t('Update role name and permissions.'),
                submit: t('Save role'),
            };
        case 'duplicate':
            return {
                title: t('Duplicate role'),
                description: t('Create a new role based on this role.'),
                submit: t('Create role'),
            };
        default:
            return {
                title: t('Create role'),
                description: t(
                    'Create custom roles for your workspace and choose exactly which permissions they include.',
                ),
                submit: t('Create role'),
            };
    }
}

function defaultNameForMode(
    state: RoleFormDialogState,
    t: (key: string, replacements?: Record<string, string | number>) => string,
): string {
    if (state.mode === 'create') {
        return '';
    }

    if (state.mode === 'duplicate') {
        return t(':name copy', { name: state.role.label });
    }

    return state.role.label;
}

function formPropsForMode(
    mode: RoleFormMode | null,
    role: WorkspaceRole | null,
    currentWorkspace: { slug: string },
) {
    if (mode === 'edit' && role) {
        return WorkspaceRoleController.update.form({
            tenant: currentWorkspace,
            role: role.id,
        });
    }

    if (mode === 'create' || mode === 'duplicate') {
        return WorkspaceRoleController.store.form(currentWorkspace);
    }

    return null;
}
