import { LinkButton } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { WorkspaceRole } from '@/features/roles/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { manage as manageRoles } from '@/routes/workspaces/roles';

export default function RolesOverviewSummaryCard({
    roles,
    canManageRoles,
}: {
    roles: WorkspaceRole[];
    canManageRoles: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    const customRoles = roles.filter((role) => !role.is_system);
    const customRolesWithMembers = customRoles.filter(
        (role) => role.member_count > 0,
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Custom roles')}</CardTitle>
                <CardDescription>
                    {t(
                        'See how many custom roles exist and which are assigned to members.',
                    )}
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-wrap items-center justify-between gap-3">
                <div className="text-muted-foreground flex flex-wrap gap-3 text-sm">
                    <span>
                        {customRoles.length === 1
                            ? t('1 custom role')
                            : t(':count custom roles', {
                                  count: customRoles.length,
                              })}
                    </span>
                    <span>
                        {customRolesWithMembers.length === 1
                            ? t('1 custom role in use')
                            : t(':count custom roles in use', {
                                  count: customRolesWithMembers.length,
                              })}
                    </span>
                </div>

                {canManageRoles ? (
                    <LinkButton
                        size="sm"
                        href={manageRoles(currentWorkspace).url}
                    >
                        {t('Manage roles')}
                    </LinkButton>
                ) : null}
            </CardContent>
        </Card>
    );
}
