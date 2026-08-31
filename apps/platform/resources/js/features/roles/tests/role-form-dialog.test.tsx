import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import type {
    WorkspacePermissionGroup,
    WorkspaceRole,
} from '@/features/roles/types';

vi.mock('@/hooks/use-translation', () => ({
    useTranslation: () => ({
        t: (key: string, replacements?: Record<string, string | number>) => {
            if (!replacements) {
                return key;
            }

            return Object.entries(replacements).reduce(
                (result, [token, value]) =>
                    result.replace(`:${token}`, String(value)),
                key,
            );
        },
        locale: 'en',
        availableLocales: [],
    }),
}));

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
    }: {
        children: (props: {
            processing: boolean;
            errors: Record<string, string>;
        }) => React.ReactNode;
    }) => <form>{children({ processing: false, errors: {} })}</form>,
}));

vi.mock(
    '@/actions/App/Tenancy/Http/Controllers/WorkspaceRoleController',
    () => {
        const form = () => ({ action: '/roles', method: 'post' as const });

        return {
            default: {
                store: Object.assign(() => ({ url: '/roles' }), { form }),
                update: Object.assign(() => ({ url: '/roles/1' }), { form }),
            },
        };
    },
);

const { default: RoleFormDialog } =
    await import('@/features/roles/role-form-dialog');

const permissionGroups: WorkspacePermissionGroup[] = [
    {
        value: 'dossiers',
        label: 'Dossiers',
        permissions: [
            {
                value: 'dossiers.view',
                label: 'View dossiers',
                description: 'View dossier records',
                granted_to: ['adviser'],
            },
        ],
    },
];

const role = {
    id: 7,
    value: 'adviser',
    label: 'Adviser',
    is_system: false,
    member_count: 2,
} as WorkspaceRole;

describe('RoleFormDialog', () => {
    it('shows create mode title, permission hint, and disables submit', () => {
        render(
            <RoleFormDialog
                state={{ mode: 'create' }}
                permissionGroups={permissionGroups}
                onOpenChange={vi.fn()}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Create role' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Choose at least one permission before saving this role.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Create role' }),
        ).toBeDisabled();
    });

    it('shows edit mode copy and preselects granted permissions', () => {
        render(
            <RoleFormDialog
                state={{ mode: 'edit', role }}
                permissionGroups={permissionGroups}
                onOpenChange={vi.fn()}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Edit role' }),
        ).toBeInTheDocument();
        expect(screen.getByDisplayValue('Adviser')).toBeInTheDocument();
        expect(
            screen.getByRole('checkbox', { name: /View dossiers/ }),
        ).toBeChecked();
        expect(
            screen.queryByText(
                'Choose at least one permission before saving this role.',
            ),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save role' })).toBeEnabled();
    });

    it('shows duplicate mode with copy name and the same permission hint when empty', async () => {
        const user = userEvent.setup();

        render(
            <RoleFormDialog
                state={{ mode: 'duplicate', role }}
                permissionGroups={permissionGroups}
                onOpenChange={vi.fn()}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Duplicate role' }),
        ).toBeInTheDocument();
        expect(screen.getByDisplayValue('Adviser copy')).toBeInTheDocument();
        expect(
            screen.getByRole('checkbox', { name: /View dossiers/ }),
        ).toBeChecked();

        await user.click(
            screen.getByRole('checkbox', { name: /View dossiers/ }),
        );

        expect(
            screen.getByText(
                'Choose at least one permission before saving this role.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Create role' }),
        ).toBeDisabled();
    });
});
