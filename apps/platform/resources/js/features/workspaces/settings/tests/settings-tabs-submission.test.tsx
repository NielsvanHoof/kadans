import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock(
    '@/actions/App/Tenancy/Http/Controllers/WorkspaceSettingsController',
    () => ({
        default: {
            update: {
                form: () => ({
                    action: '/workspaces/acme/settings',
                    method: 'patch',
                }),
            },
            updateFirm: {
                form: () => ({
                    action: '/workspaces/acme/settings/firm',
                    method: 'patch',
                }),
            },
        },
    }),
);

const submitted: Record<string, FormDataEntryValue>[] = [];

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
        // Inertia-only — must not leak onto the DOM <form>.
        options: _options,
        onError: _onError,
        className: _className,
        action,
        method,
    }: {
        children: (state: {
            processing: boolean;
            errors: Record<string, string>;
        }) => React.ReactNode;
        options?: unknown;
        onError?: unknown;
        action?: string;
        method?: string;
        className?: string;
    }) => (
        <form
            action={action}
            method={method}
            onSubmit={(event) => {
                event.preventDefault();
                submitted.push(
                    Object.fromEntries(
                        new FormData(event.currentTarget).entries(),
                    ),
                );
            }}
        >
            {children({ processing: false, errors: {} })}
        </form>
    ),
}));

const { default: FirmSettingsTabs } =
    await import('@/features/workspaces/settings/firm-settings-tabs');

function settings(): App.Tenancy.Data.TenantSettingsData {
    const number = (
        override: number | null,
        platform: number | null,
        max: number | null,
    ): App.Tenancy.Data.FirmSettingData => ({
        override,
        effective: override ?? platform,
        platform_default: platform,
        min: 1,
        max,
    });

    return {
        ai: {
            document_overview: true,
            checklist_draft: false,
            reminder_draft: true,
        },
        security: {
            two_factor_enforcement_override: 'all',
            two_factor_enforcement: 'all',
            platform_two_factor_enforcement: 'by_permission',
            allowed_enforcement_levels: ['by_permission', 'all'],
        },
        portal: {
            session_idle_minutes: number(5, 15, 15),
            session_absolute_minutes: number(null, 240, 240),
        },
        reminders: { default_interval_days: number(7, null, 30) },
        retention: {
            archived_days: number(730, 1095, 3650),
            activity_log_days: number(null, 1095, 3650),
        },
    };
}

/**
 * The five policy tabs share one form and one save button, so React Aria must
 * keep the panels the reader has not opened mounted. If it ever stops, the
 * fields on those tabs submit nothing — and because an absent field is not the
 * same as an empty one, saving from the Signing in tab would quietly wipe the
 * firm's portal and retention choices.
 */
describe('firm settings tabs', () => {
    it('submits every policy field even from a tab the reader never opened', async () => {
        submitted.length = 0;
        const user = userEvent.setup();

        render(
            <FirmSettingsTabs
                firm={{ name: 'Acme', slug: 'acme' }}
                settings={settings()}
            />,
        );

        await user.click(screen.getByRole('tab', { name: /Signing in/ }));
        await user.click(screen.getByRole('button', { name: 'Save settings' }));

        expect(submitted).toHaveLength(1);
        expect(Object.keys(submitted[0]).sort()).toEqual([
            'ai_checklist_draft_enabled',
            'ai_document_overview_enabled',
            'ai_reminder_draft_enabled',
            'default_reminder_interval_days',
            'portal_session_absolute_minutes',
            'portal_session_idle_minutes',
            'retention_activity_log_days',
            'retention_archived_days',
            'two_factor_enforcement',
        ]);
    });

    it('keeps each field’s stored value, and sends an unset one as empty', async () => {
        submitted.length = 0;
        const user = userEvent.setup();

        render(
            <FirmSettingsTabs
                firm={{ name: 'Acme', slug: 'acme' }}
                settings={settings()}
            />,
        );

        await user.click(screen.getByRole('tab', { name: /Retention/ }));
        await user.click(screen.getByRole('button', { name: 'Save settings' }));

        expect(submitted[0]).toMatchObject({
            portal_session_idle_minutes: '5',
            // Inherited: empty, never a zero.
            portal_session_absolute_minutes: '',
            retention_archived_days: '730',
            default_reminder_interval_days: '7',
            two_factor_enforcement: 'all',
            // Unchecked boxes still say so, via their hidden input.
            ai_checklist_draft_enabled: '0',
            ai_document_overview_enabled: '1',
        });
    });

    it('leaves exactly one panel active, with the rest inert and hidden', async () => {
        const user = userEvent.setup();

        const { container } = render(
            <FirmSettingsTabs
                firm={{ name: 'Acme', slug: 'acme' }}
                settings={settings()}
            />,
        );

        await user.click(screen.getByRole('tab', { name: /Client portal/ }));

        const panels = container.querySelectorAll('[data-slot="tabs-content"]');
        const inert = container.querySelectorAll(
            '[data-slot="tabs-content"][data-inert]',
        );

        // React Aria leaves inert panels on screen, so they must also carry the
        // class that hides them — otherwise every panel stacks up at once.
        expect(panels.length - inert.length).toBe(1);
        inert.forEach((panel) => {
            expect(panel.className).toContain('data-[inert]:hidden');
        });
    });

    it('does not offer the policy save button on the firm details tab', () => {
        render(
            <FirmSettingsTabs
                firm={{ name: 'Acme', slug: 'acme' }}
                settings={settings()}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Save settings' }),
        ).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Save firm details' }),
        ).toBeInTheDocument();
    });
});
