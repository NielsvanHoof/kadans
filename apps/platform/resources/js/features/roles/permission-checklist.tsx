import { useMemo } from 'react';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import type { WorkspacePermissionGroup } from '@/features/roles/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Grouped permission picker shared by the create, edit, and duplicate forms.
 */
export default function PermissionChecklist({
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
        <FieldGroup>
            <ButtonGroup>
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
            </ButtonGroup>

            {/*
              `legend` has to be the fieldset's first child to name the group,
              so the bulk toggles sit on their own row beneath it rather than
              inside a shared header div.
            */}
            {permissionGroups.map((group) => (
                <FieldSet key={group.value} className="gap-2">
                    <FieldLegend
                        variant="label"
                        className="mb-0 tracking-wide uppercase"
                    >
                        {group.label}
                    </FieldLegend>

                    <ButtonGroup>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => toggleGroupPermissions(group, true)}
                        >
                            {t('All')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => toggleGroupPermissions(group, false)}
                        >
                            {t('None')}
                        </Button>
                    </ButtonGroup>

                    <FieldGroup className="gap-2">
                        {group.permissions.map((permission) => {
                            const checkboxId = `permission-${group.value}-${permission.value}`;

                            return (
                                <Field
                                    key={permission.value}
                                    orientation="horizontal"
                                >
                                    <Checkbox
                                        id={checkboxId}
                                        name="permissions[]"
                                        value={permission.value}
                                        isSelected={selectedPermissionSet.has(
                                            permission.value,
                                        )}
                                        onChange={(checked) =>
                                            togglePermission(
                                                permission.value,
                                                checked === true,
                                            )
                                        }
                                    />
                                    <FieldContent>
                                        <FieldLabel
                                            htmlFor={checkboxId}
                                            className="cursor-pointer font-medium"
                                        >
                                            {permission.label}
                                        </FieldLabel>
                                        <FieldDescription>
                                            {permission.description}
                                        </FieldDescription>
                                    </FieldContent>
                                </Field>
                            );
                        })}
                    </FieldGroup>
                </FieldSet>
            ))}
        </FieldGroup>
    );
}
