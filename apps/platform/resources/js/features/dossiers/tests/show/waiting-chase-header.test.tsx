import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Dossier } from '@/features/dossiers/types';
import type { AccessGrant } from '@/features/portal/types';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';

const useDossierCapabilities = vi.fn();

vi.mock('@/hooks/use-translation', () => mockUseTranslation());
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

vi.mock('@/features/dossiers/permissions/dossier-permissions-context', () => ({
    useDossierCapabilities,
}));

vi.mock(
    '@/features/dossiers/follow-up/dossier-reminder-compose-dialog',
    () => ({
        default: () => <div data-testid="reminder-dialog" />,
    }),
);

const { default: WaitingChaseHeader } =
    await import('@/features/dossiers/show/waiting-chase-header');

function grant(overrides: Partial<AccessGrant> = {}): AccessGrant {
    return {
        id: 1,
        expires_at: '2026-08-29T09:00:00+00:00',
        revoked_at: null,
        last_used_at: null,
        is_valid: true,
        ...overrides,
    } as AccessGrant;
}

function dossier(overrides: Partial<Dossier> = {}): Dossier {
    return {
        id: 7,
        access_grants: [],
        last_client_message_sent_at: null,
        has_outstanding_client_items: true,
        ...overrides,
    } as unknown as Dossier;
}

function renderHeader(
    overrides: Partial<Dossier> = {},
    onSelectAccess = vi.fn(),
) {
    render(
        <WaitingChaseHeader
            dossier={dossier(overrides)}
            openCount={3}
            onSelectAccess={onSelectAccess}
        />,
    );

    return { onSelectAccess };
}

beforeEach(() => {
    useDossierCapabilities.mockReturnValue({ canSendReminder: true });
});

describe('WaitingChaseHeader', () => {
    /**
     * Grants last seven days, so a dead link is the most common reason nothing
     * arrived. Whether the client can act at all is the first thing this says.
     */
    it('states when the active portal link expires', () => {
        renderHeader({ access_grants: [grant()] });

        expect(
            screen.getByText('Portal link expires :date'),
        ).toBeInTheDocument();
    });

    it('warns when every link has lapsed', () => {
        renderHeader({ access_grants: [grant({ is_valid: false })] });

        expect(
            screen.getByText(
                'No active portal link — the client cannot upload.',
            ),
        ).toBeInTheDocument();
    });

    it('distinguishes a link that lapsed from one never sent', () => {
        renderHeader({ access_grants: [] });

        expect(
            screen.getByText('No portal link has been sent yet.'),
        ).toBeInTheDocument();
    });

    it('reports the open count and when the client was last asked', () => {
        renderHeader({
            last_client_message_sent_at: '2026-08-20T09:00:00+00:00',
        });

        expect(screen.getByText(/:count items open/)).toBeInTheDocument();
        expect(screen.getByText(/last asked :date/)).toBeInTheDocument();
    });

    it('says so when the client has never been asked', () => {
        renderHeader({ last_client_message_sent_at: null });

        expect(screen.getByText(/never asked/)).toBeInTheDocument();
    });

    /**
     * Sending a reminder mints a fresh grant as well as mailing it, so it is
     * the right action whether the current link is alive or dead.
     */
    it('offers the reminder dialog to staff who may send one', () => {
        renderHeader({ access_grants: [grant({ is_valid: false })] });

        expect(screen.getByTestId('reminder-dialog')).toBeInTheDocument();
    });

    it('falls back to the access panel without reminder rights', async () => {
        const user = userEvent.setup();
        useDossierCapabilities.mockReturnValue({ canSendReminder: false });

        const { onSelectAccess } = renderHeader({ access_grants: [grant()] });

        expect(screen.queryByTestId('reminder-dialog')).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: /Open access/ }));

        expect(onSelectAccess).toHaveBeenCalledOnce();
    });

    it('offers an invite instead when no link was ever created', () => {
        useDossierCapabilities.mockReturnValue({ canSendReminder: false });

        renderHeader({ access_grants: [] });

        expect(
            screen.getByRole('button', { name: /Invite client/ }),
        ).toBeInTheDocument();
    });
});
