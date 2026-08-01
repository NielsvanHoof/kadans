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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead scope="col" className="min-w-56">
                        {t('Permission')}
                    </TableHead>
                    {roles.map((role) => (
                        <TableHead
                            key={role.value}
                            scope="col"
                            className="text-center"
                        >
                            <span className="block text-foreground">
                                {role.label}
                            </span>
                            <span className="block text-xs font-normal">
                                {role.member_count === 1
                                    ? t('1 member')
                                    : t(':count members', {
                                          count: role.member_count,
                                      })}
                            </span>
                        </TableHead>
                    ))}
                </TableRow>
            </TableHeader>
            <TableBody>
                {permissionGroups.map((group) => (
                    <Fragment key={group.value}>
                        <TableRow className="hover:bg-transparent">
                            <TableCell
                                colSpan={roles.length + 1}
                                className="bg-muted/40 text-xs font-semibold tracking-wide uppercase"
                            >
                                {group.label}
                            </TableCell>
                        </TableRow>
                        {group.permissions.map((permission) => (
                            <TableRow key={permission.value}>
                                <TableCell>
                                    <span className="block font-medium">
                                        {permission.label}
                                    </span>
                                    <span className="block text-xs text-pretty whitespace-normal text-muted-foreground">
                                        {permission.description}
                                    </span>
                                </TableCell>
                                {roles.map((role) => (
                                    <TableCell
                                        key={role.value}
                                        className="text-center"
                                    >
                                        <GrantMark
                                            granted={permission.granted_to.includes(
                                                role.value,
                                            )}
                                            permissionLabel={permission.label}
                                            roleLabel={role.label}
                                        />
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))}
                    </Fragment>
                ))}
            </TableBody>
        </Table>
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
                    <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
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
                                <p className="text-xs text-pretty text-muted-foreground">
                                    {permission.description}
                                </p>
                                {permission.granted_to.length === 0 ? (
                                    <p className="text-xs text-muted-foreground">
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
                <Check className="mx-auto size-4 text-primary" aria-hidden />
            ) : (
                <Minus
                    className="mx-auto size-4 text-muted-foreground/40"
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
