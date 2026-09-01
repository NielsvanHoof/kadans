import { usePage } from '@inertiajs/react';
import { usePermissions } from '@/hooks/use-permissions';
import { useTranslation } from '@/hooks/use-translation';
import { index as dataSubjectRequestIndex } from '@/routes/workspaces/data-subject-requests';
import { index as membersIndex } from '@/routes/workspaces/members';
import { index as rolesIndex } from '@/routes/workspaces/roles';
import { index as settingsIndex } from '@/routes/workspaces/settings';
import type { NavItem } from '@/types';

/**
 * The firm administration sections this user may reach, in rail order.
 *
 * Single source of truth for both the sidebar's "Admin" entry (which links to
 * the first permitted section) and the rail inside AdminLayout, so the two can
 * never disagree about what the area contains.
 *
 * Reads `current_firm` rather than useCurrentWorkspace() because the sidebar
 * calls this on every page, including the ones with no firm in context.
 */
export function useAdminSections(): NavItem[] {
    const { current_firm: currentWorkspace } = usePage().props;
    const { can } = usePermissions();
    const { t } = useTranslation();

    if (!currentWorkspace) {
        return [];
    }

    return [
        ...(can('members.view')
            ? [
                  {
                      title: t('Members'),
                      href: membersIndex(currentWorkspace),
                      icon: null,
                  },
              ]
            : []),
        ...(can('roles.view')
            ? [
                  {
                      title: t('Roles & permissions'),
                      href: rolesIndex(currentWorkspace),
                      icon: null,
                  },
              ]
            : []),
        ...(can('data_subject.view')
            ? [
                  {
                      title: t('Privacy requests'),
                      href: dataSubjectRequestIndex(currentWorkspace),
                      icon: null,
                  },
              ]
            : []),
        ...(can('tenant.settings.manage')
            ? [
                  {
                      title: t('Firm settings'),
                      href: settingsIndex(currentWorkspace),
                      icon: null,
                  },
              ]
            : []),
    ];
}
