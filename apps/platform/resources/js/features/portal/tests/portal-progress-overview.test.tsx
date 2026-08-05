import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { PortalDossier } from '@/features/portal/types';

vi.mock('@/hooks/use-translation', () => ({
    useTranslation: () => ({
        t: (key: string, replacements?: Record<string, string | number>) => {
            if (!replacements) {
                return key;
            }

            return Object.entries(replacements).reduce(
                (result, [name, value]) =>
                    result.replace(`:${name}`, String(value)),
                key,
            );
        },
        locale: 'en',
        availableLocales: [],
    }),
}));

const { default: PortalProgressOverview } =
    await import('@/features/portal/portal-progress-overview');

function dossier(overrides: Partial<PortalDossier> = {}): PortalDossier {
    return {
        due_date: null,
        progress: {
            total: 3,
            completed: 1,
            remaining: 2,
            approved: 0,
            next_incomplete: { id: 42, title: 'Bank statement' },
        },
        ...overrides,
    } as PortalDossier;
}

describe('PortalProgressOverview', () => {
    it('renders nothing when there are no items', () => {
        const { container } = render(
            <PortalProgressOverview
                dossier={dossier({
                    progress: {
                        total: 0,
                        completed: 0,
                        remaining: 0,
                        approved: 0,
                        next_incomplete: null,
                    },
                })}
                firmName="Acme"
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('shows remaining count and a continue link to the next item', () => {
        render(<PortalProgressOverview dossier={dossier()} firmName="Acme" />);

        expect(screen.getByText('2 items remaining')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Continue/ })).toHaveAttribute(
            'href',
            '#request-42',
        );
        expect(screen.getByRole('progressbar')).toHaveAttribute(
            'aria-valuenow',
            '1',
        );
    });

    it('shows completion copy and hides continue when everything is submitted', () => {
        render(
            <PortalProgressOverview
                dossier={dossier({
                    progress: {
                        total: 2,
                        completed: 2,
                        remaining: 0,
                        approved: 1,
                        next_incomplete: null,
                    },
                })}
                firmName="Acme"
            />,
        );

        expect(
            screen.getByText('Everything has been submitted'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Acme will review your information. You can return here if changes are requested.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Continue/ }),
        ).not.toBeInTheDocument();
    });

    it('mentions the due date when one is set', () => {
        render(
            <PortalProgressOverview
                dossier={dossier({ due_date: '2026-03-15' })}
                firmName="Acme"
            />,
        );

        expect(screen.getByText(/Please finish by/)).toBeInTheDocument();
    });
});
