/*
 * Plain semantic <table> rather than the React Aria Table primitive.
 * This grid uses colSpan group-separator rows, which a React Aria collection
 * cannot express, and it needs none of the collection behaviour (no selection,
 * no row actions). Native table semantics are fully accessible here.
 */
import { Check, Minus } from 'lucide-react';
import { Fragment } from 'react';
import EmptyState from '@/components/primitives/empty-state';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type {
    WorkspacePermissionGroup,
    WorkspaceRole,
} from '@/features/roles/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Which permission each role grants, as persisted for this firm.
 *
 * Reads as a wide grid on md and up; below that the same data is stacked one
 * permission at a time, which beats a five-column table squeezed onto a phone.
 */
export default function RoleMatrixCard({
    roles,
    permissionGroups,
}: {
    roles: WorkspaceRole[];
    permissionGroups: WorkspacePermissionGroup[];
}) {
    const { t } = useTranslation();

    if (roles.length === 0 || permissionGroups.length === 0) {
        return (
            <Card>
                <CardContent>
                    <EmptyState
                        title={t('No roles are set up for this firm.')}
                    />
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Permissions by role')}</CardTitle>
                <CardDescription>
                    {t(
                        'Every member holds exactly one role. Changing a member’s role changes what they can do immediately.',
                    )}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="hidden md:block">
                    <PermissionGrid
                        roles={roles}
                        permissionGroups={permissionGroups}
                    />
                </div>
                <div className="md:hidden">
                    <PermissionStack
                        roles={roles}
                        permissionGroups={permissionGroups}
                    />
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * Roles across, permissions down. Scrolls horizontally rather than the page.
 */
function PermissionGrid({
    roles,
    permissionGroups,
}: {
    roles: WorkspaceRole[];
    permissionGroups: WorkspacePermissionGroup[];
}) {
    const { t } = useTranslation();

    return (
        // The scroll container the doc comment above promises. Without it a
        // workspace with several custom roles pushes the whole page sideways
        // in the 768-1023px band, where this grid is visible but narrow.
        <div className="overflow-x-auto">
            <table className="w-full caption-bottom text-xs">
                <thead className="[&_tr]:border-b">
                    <tr className="hover:bg-muted/50 border-b transition-colors">
                        <th className="min-w-56 p-2 text-left align-middle font-medium">
                            {t('Permission')}
                        </th>
                        {roles.map((role) => (
                            <th
                                className="p-2 text-center align-middle font-medium"
                                key={role.value}
                            >
                                <span className="text-foreground block">
                                    {role.label}
                                </span>
                                <span className="block text-xs font-normal">
                                    {role.member_count === 1
                                        ? t('1 member')
                                        : t(':count members', {
                                              count: role.member_count,
                                          })}
                                </span>
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="[&_tr:last-child]:border-0">
                    {permissionGroups.map((group) => (
                        <Fragment key={group.value}>
                            <tr className="hover:bg-muted/50 border-b transition-colors hover:bg-transparent">
                                <td
                                    className="bg-muted/40 p-2 align-middle text-xs font-semibold tracking-wide uppercase"
                                    colSpan={roles.length + 1}
                                >
                                    {group.label}
                                </td>
                            </tr>
                            {group.permissions.map((permission) => (
                                <tr
                                    className="hover:bg-muted/50 border-b transition-colors"
                                    key={permission.value}
                                >
                                    <td className="p-2 align-middle">
                                        <span className="block font-medium">
                                            {permission.label}
                                        </span>
                                        <span className="text-muted-foreground block text-xs text-pretty whitespace-normal">
                                            {permission.description}
                                        </span>
                                    </td>
                                    {roles.map((role) => (
                                        <td
                                            className="p-2 text-center align-middle"
                                            key={role.value}
                                        >
                                            <GrantMark
                                                granted={permission.granted_to.includes(
                                                    role.value,
                                                )}
                                                permissionLabel={
                                                    permission.label
                                                }
                                                roleLabel={role.label}
                                            />
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </Fragment>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * One permission at a time, listing the roles that hold it as chips.
 */
function PermissionStack({
    roles,
    permissionGroups,
}: {
    roles: WorkspaceRole[];
    permissionGroups: WorkspacePermissionGroup[];
}) {
    const { t } = useTranslation();
    const labelsByRoleName = new Map(
        roles.map((role) => [role.value, role.label]),
    );

    return (
        <div className="flex flex-col gap-6">
            {permissionGroups.map((group) => (
                <section key={group.value} className="flex flex-col gap-3">
                    <h3 className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">
                        {group.label}
                    </h3>
                    <ul className="flex flex-col gap-4">
                        {group.permissions.map((permission) => (
                            <li
                                key={permission.value}
                                className="flex flex-col gap-1.5"
                            >
                                <p className="font-medium">
                                    {permission.label}
                                </p>
                                <p className="text-muted-foreground text-xs text-pretty">
                                    {permission.description}
                                </p>
                                {permission.granted_to.length === 0 ? (
                                    <p className="text-muted-foreground text-xs">
                                        {t('No role grants this.')}
                                    </p>
                                ) : (
                                    <div className="flex flex-wrap gap-1.5">
                                        {permission.granted_to.map(
                                            (roleName) => (
                                                <Badge
                                                    key={roleName}
                                                    variant="secondary"
                                                >
                                                    {labelsByRoleName.get(
                                                        roleName,
                                                    ) ?? roleName}
                                                </Badge>
                                            ),
                                        )}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
        </div>
    );
}

/**
 * Grid cell state. The icon is decorative; the fact is carried in text for
 * screen readers, since a bare glyph in a table cell reads as nothing.
 */
function GrantMark({
    granted,
    permissionLabel,
    roleLabel,
}: {
    granted: boolean;
    permissionLabel: string;
    roleLabel: string;
}) {
    const { t } = useTranslation();

    return (
        <>
            {granted ? (
                <Check className="text-primary mx-auto size-4" aria-hidden />
            ) : (
                <Minus
                    className="text-muted-foreground/40 mx-auto size-4"
                    aria-hidden
                />
            )}
            <span className="sr-only">
                {granted
                    ? t(':role can :permission', {
                          role: roleLabel,
                          permission: permissionLabel,
                      })
                    : t(':role cannot :permission', {
                          role: roleLabel,
                          permission: permissionLabel,
                      })}
            </span>
        </>
    );
}
