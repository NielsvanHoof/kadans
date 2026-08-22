import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());
vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
    }: {
        children?: ((props: Record<string, unknown>) => unknown) | unknown;
    }) =>
        typeof children === 'function'
            ? children({ errors: {}, processing: false, submit: () => {} })
            : children,
}));

const { default: PortalSessionNotice } =
    await import('@/features/portal/portal-session-notice');

const NOW = new Date('2026-08-21T10:00:00.000Z');

function isoInSeconds(seconds: number): string {
    return new Date(NOW.getTime() + seconds * 1000).toISOString();
}

describe('PortalSessionNotice', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(NOW);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders nothing while the session is comfortably alive', () => {
        const { container } = render(
            <PortalSessionNotice idleExpiresAt={isoInSeconds(900)} />,
        );

        // A permanent countdown on a document-upload page would just be anxiety.
        expect(container).toBeEmptyDOMElement();
    });

    it('warns and offers to keep the session open near the deadline', () => {
        render(<PortalSessionNotice idleExpiresAt={isoInSeconds(120)} />);

        expect(
            screen.getByText('Your secure session is about to end'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Keep my session open' }),
        ).toBeInTheDocument();
    });

    it('drops the keep-open button once the deadline has passed', () => {
        render(<PortalSessionNotice idleExpiresAt={isoInSeconds(-60)} />);

        expect(
            screen.getByText('Your secure session has ended'),
        ).toBeInTheDocument();
        // Pressing it would only 302 them to the ended page.
        expect(
            screen.queryByRole('button', { name: 'Keep my session open' }),
        ).not.toBeInTheDocument();
    });

    it('reassures the client that submitted work survives', () => {
        render(<PortalSessionNotice idleExpiresAt={isoInSeconds(-60)} />);

        expect(
            screen.getByText(
                'Everything you already submitted is saved. Open the link from your email again to carry on.',
            ),
        ).toBeInTheDocument();
    });

    it('announces itself politely rather than interrupting', () => {
        const { container } = render(
            <PortalSessionNotice idleExpiresAt={isoInSeconds(120)} />,
        );

        expect(container.querySelector('[aria-live="polite"]')).not.toBeNull();
    });
});
