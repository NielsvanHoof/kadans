import { ChevronDownIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import type {
    WorkspacePermission,
    WorkspacePermissionGroup,
} from '@/features/roles/types';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

type VisibleGroup = {
    /** The unfiltered group — bulk toggles and counts always read from this. */
    group: WorkspacePermissionGroup;
    /** Only the permissions matching the current search. */
    permissions: WorkspacePermission[];
};

/**
 * Grouped permission picker shared by the create, edit, and duplicate forms.
 *
 * The checkboxes carry no form name: the submitted payload comes from the
 * owning form's hidden inputs. Search and collapse may therefore unmount a
 * checked permission without silently dropping it from the request.
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

    const [searchTerm, setSearchTerm] = useState('');
    const [expandedGroups, setExpandedGroups] = useState<Set<string>>(
        () =>
            new Set(
                permissionGroups
                    .filter((group) =>
                        group.permissions.some((permission) =>
                            selectedPermissions.includes(permission.value),
                        ),
                    )
                    .map((group) => group.value),
            ),
    );

    const searchQuery = searchTerm.trim().toLowerCase();
    const isSearching = searchQuery !== '';

    const totalPermissionCount = useMemo(
        () =>
            permissionGroups.reduce(
                (total, group) => total + group.permissions.length,
                0,
            ),
        [permissionGroups],
    );

    const selectedPermissionCount = useMemo(
        () =>
            permissionGroups.reduce(
                (total, group) =>
                    total +
                    group.permissions.filter((permission) =>
                        selectedPermissionSet.has(permission.value),
                    ).length,
                0,
            ),
        [permissionGroups, selectedPermissionSet],
    );

    const visibleGroups = useMemo<VisibleGroup[]>(() => {
        if (!isSearching) {
            return permissionGroups.map((group) => ({
                group,
                permissions: group.permissions,
            }));
        }

        return permissionGroups
            .map((group) => ({
                group,
                permissions: group.permissions.filter((permission) =>
                    `${permission.label} ${permission.description}`
                        .toLowerCase()
                        .includes(searchQuery),
                ),
            }))
            .filter(({ permissions }) => permissions.length > 0);
    }, [permissionGroups, isSearching, searchQuery]);

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

    const toggleGroupExpansion = (groupValue: string, expanded: boolean) => {
        setExpandedGroups((currentlyExpanded) => {
            const nextExpanded = new Set(currentlyExpanded);

            if (expanded) {
                nextExpanded.add(groupValue);
            } else {
                nextExpanded.delete(groupValue);
            }

            return nextExpanded;
        });
    };

    return (
        <FieldGroup>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Input
                    type="search"
                    value={searchTerm}
                    maxLength={255}
                    placeholder={t('Search permissions')}
                    aria-label={t('Search permissions')}
                    className="w-full sm:max-w-xs"
                    onChange={(event) => setSearchTerm(event.target.value)}
                />

                <div className="flex flex-wrap items-center gap-2">
                    <p
                        className="text-muted-foreground text-xs"
                        aria-live="polite"
                    >
                        {t(':selected of :total selected', {
                            selected: selectedPermissionCount,
                            total: totalPermissionCount,
                        })}
                    </p>

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

                    {/* A search force-expands every match, so these would be inert. */}
                    {isSearching ? null : (
                        <ButtonGroup>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setExpandedGroups(
                                        new Set(
                                            permissionGroups.map(
                                                (group) => group.value,
                                            ),
                                        ),
                                    )
                                }
                            >
                                {t('Expand all')}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setExpandedGroups(new Set())}
                            >
                                {t('Collapse all')}
                            </Button>
                        </ButtonGroup>
                    )}
                </div>
            </div>

            {visibleGroups.length === 0 ? (
                <p className="text-muted-foreground text-xs">
                    {t('No permissions match your search.')}
                </p>
            ) : (
                visibleGroups.map(({ group, permissions }) => {
                    const groupSelectedCount = group.permissions.filter(
                        (permission) =>
                            selectedPermissionSet.has(permission.value),
                    ).length;
                    const isExpanded =
                        isSearching || expandedGroups.has(group.value);

                    return (
                        <Collapsible
                            key={group.value}
                            isExpanded={isExpanded}
                            onExpandedChange={(expanded) =>
                                toggleGroupExpansion(group.value, expanded)
                            }
                        >
                            {/*
                              `legend` has to be the fieldset's first child to name the
                              group, so the toggles sit on their own row beneath it
                              rather than inside a shared header div.
                            */}
                            <FieldSet className="gap-2 rounded-md border p-3">
                                <FieldLegend
                                    variant="label"
                                    className="mb-0 tracking-wide uppercase"
                                >
                                    {group.label}
                                </FieldLegend>

                                <div className="flex flex-wrap items-center gap-2">
                                    <CollapsibleTrigger
                                        variant="outline"
                                        size="sm"
                                        className="h-auto items-start md:h-auto"
                                        aria-label={t(
                                            ':name permissions, :selected of :total selected',
                                            {
                                                name: group.label,
                                                selected: groupSelectedCount,
                                                total: group.permissions.length,
                                            },
                                        )}
                                    >
                                        <ChevronDownIcon
                                            aria-hidden="true"
                                            className={cn(
                                                'size-3.5 transition-transform motion-reduce:transition-none',
                                                isExpanded && 'rotate-180',
                                            )}
                                        />
                                        {t(':selected of :total', {
                                            selected: groupSelectedCount,
                                            total: group.permissions.length,
                                        })}
                                    </CollapsibleTrigger>

                                    <ButtonGroup>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                toggleGroupPermissions(
                                                    group,
                                                    true,
                                                )
                                            }
                                        >
                                            {t('All')}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                toggleGroupPermissions(
                                                    group,
                                                    false,
                                                )
                                            }
                                        >
                                            {t('None')}
                                        </Button>
                                    </ButtonGroup>
                                </div>

                                {/*
                                  The panel is always role="group" (RAC allows only
                                  group|region), so it must stay unnamed — labelling it
                                  would collide with the fieldset that already names
                                  this group and make `getByRole('group', { name })`
                                  ambiguous.
                                */}
                                <CollapsibleContent>
                                    <FieldGroup className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                        {permissions.map((permission) => {
                                            const checkboxId = `permission-${group.value}-${permission.value}`;

                                            return (
                                                <Field
                                                    key={permission.value}
                                                    orientation="horizontal"
                                                >
                                                    <Checkbox
                                                        id={checkboxId}
                                                        isSelected={selectedPermissionSet.has(
                                                            permission.value,
                                                        )}
                                                        onChange={(checked) =>
                                                            togglePermission(
                                                                permission.value,
                                                                checked ===
                                                                    true,
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
                                                            {
                                                                permission.description
                                                            }
                                                        </FieldDescription>
                                                    </FieldContent>
                                                </Field>
                                            );
                                        })}
                                    </FieldGroup>
                                </CollapsibleContent>
                            </FieldSet>
                        </Collapsible>
                    );
                })
            )}
        </FieldGroup>
    );
}
