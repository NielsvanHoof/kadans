import { useMemo } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FieldLabel } from '@/components/ui/field';
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
