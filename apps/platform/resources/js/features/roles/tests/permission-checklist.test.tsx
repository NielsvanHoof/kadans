import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { WorkspacePermissionGroup } from '@/features/roles/types';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: PermissionChecklist } =
    await import('@/features/roles/permission-checklist');

const groups: WorkspacePermissionGroup[] = [
    {
        value: 'dossiers',
        label: 'Dossiers',
        permissions: [
            {
                value: 'dossiers.view',
                label: 'View dossiers',
                description: 'View dossier records',
                granted_to: [],
            },
            {
                value: 'dossiers.create',
                label: 'Create dossiers',
                description: 'Create new dossier records',
                granted_to: [],
            },
        ],
    },
    {
        value: 'clients',
        label: 'Clients',
        permissions: [
            {
                value: 'clients.view',
                label: 'View clients',
                description: 'View client records',
                granted_to: [],
            },
        ],
    },
];

function setup(
    selectedPermissions: string[] = [],
    overrides: Partial<{
        onSelectAll: () => void;
        onClearAll: () => void;
    }> = {},
) {
    const onSelectedPermissionsChange = vi.fn();
    const onSelectAll = overrides.onSelectAll ?? vi.fn();
    const onClearAll = overrides.onClearAll ?? vi.fn();

    render(
        <PermissionChecklist
            permissionGroups={groups}
            selectedPermissions={selectedPermissions}
            onSelectedPermissionsChange={onSelectedPermissionsChange}
            onSelectAll={onSelectAll}
            onClearAll={onClearAll}
        />,
    );

    return { onSelectedPermissionsChange, onSelectAll, onClearAll };
}

describe('PermissionChecklist', () => {
    it('reflects which permissions are already selected', () => {
        setup(['dossiers.view', 'clients.view']);

        expect(
            screen.getByRole('checkbox', { name: /View dossiers/ }),
        ).toBeChecked();
        expect(
            screen.getByRole('checkbox', { name: /Create dossiers/ }),
        ).not.toBeChecked();
        expect(
            screen.getByRole('checkbox', { name: /View clients/ }),
        ).toBeChecked();
    });

    it('adds a permission to the selection when its checkbox is checked', async () => {
        const user = userEvent.setup();
        const { onSelectedPermissionsChange } = setup(['dossiers.view']);

        await user.click(
            screen.getByRole('checkbox', { name: /Create dossiers/ }),
        );

        expect(onSelectedPermissionsChange).toHaveBeenCalledWith([
            'dossiers.view',
            'dossiers.create',
        ]);
    });

    it('removes a permission from the selection when its checkbox is unchecked', async () => {
        const user = userEvent.setup();
        const { onSelectedPermissionsChange } = setup([
            'dossiers.view',
            'dossiers.create',
        ]);

        await user.click(
            screen.getByRole('checkbox', { name: /View dossiers/ }),
        );

        expect(onSelectedPermissionsChange).toHaveBeenCalledWith([
            'dossiers.create',
        ]);
    });

    it('checks every permission in a group via the group "All" button, leaving other groups untouched', async () => {
        const user = userEvent.setup();
        const { onSelectedPermissionsChange } = setup(['clients.view']);

        // Each group is a fieldset named by its legend, so the group role is
        // the scope boundary — not whichever div happens to wrap the heading.
        const dossiersGroup = screen.getByRole('group', { name: 'Dossiers' });
        await user.click(
            within(dossiersGroup).getByRole('button', { name: 'All' }),
        );

        expect(onSelectedPermissionsChange).toHaveBeenCalledWith(
            expect.arrayContaining([
                'clients.view',
                'dossiers.view',
                'dossiers.create',
            ]),
        );
        expect(
            onSelectedPermissionsChange.mock.calls[0][0] as string[],
        ).toHaveLength(3);
    });

    it('clears every permission in a group via the group "None" button, leaving other groups untouched', async () => {
        const user = userEvent.setup();
        const { onSelectedPermissionsChange } = setup([
            'dossiers.view',
            'dossiers.create',
            'clients.view',
        ]);

        const dossiersGroup = screen.getByRole('group', { name: 'Dossiers' });
        await user.click(
            within(dossiersGroup).getByRole('button', { name: 'None' }),
        );

        expect(onSelectedPermissionsChange).toHaveBeenCalledWith([
            'clients.view',
        ]);
    });

    it('wires the top-level Select all / Clear all buttons to their own callbacks', async () => {
        const user = userEvent.setup();
        const { onSelectAll, onClearAll } = setup([]);

        await user.click(screen.getByRole('button', { name: 'Select all' }));
        await user.click(screen.getByRole('button', { name: 'Clear all' }));

        expect(onSelectAll).toHaveBeenCalledTimes(1);
        expect(onClearAll).toHaveBeenCalledTimes(1);
    });
});

// `within` is used above; import it alongside the other testing-library helpers.
import { within } from '@testing-library/react';
