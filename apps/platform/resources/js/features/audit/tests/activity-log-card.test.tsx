import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { ActivityLogEntry } from '@/features/audit/types';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';
import type { Paginated } from '@/types/pagination';

vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@/hooks/use-translation', () => ({
    ...mockUseTranslation(),
    // Card copy leans on :count / :id placeholders; keep them readable in assertions.
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
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

const { default: ActivityLogCard } =
    await import('@/features/audit/activity-log-card');

function paginated(
    data: ActivityLogEntry[],
    total = data.length,
): Paginated<ActivityLogEntry> {
    return {
        data,
        total,
        current_page: 1,
        last_page: 1,
        per_page: 10,
        links: [],
    } as unknown as Paginated<ActivityLogEntry>;
}

function activity(overrides: Partial<ActivityLogEntry> = {}): ActivityLogEntry {
    return {
        id: 1,
        label: 'Updated',
        description: null,
        causer_name: 'Ada Lovelace',
        created_at: '2026-01-01T10:00:00Z',
        subject: null,
        attribute_changes: null,
        properties: { ip: null, route: null },
        ...overrides,
    } as ActivityLogEntry;
}

describe('ActivityLogCard', () => {
    it('shows an empty state and zero-event copy when there is no activity', () => {
        render(<ActivityLogCard activities={paginated([])} />);

        expect(screen.getByText('No audit events yet')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Audit events will appear here as your team works in this workspace.',
            ),
        ).toBeInTheDocument();
    });

    it('pluralizes the event count', () => {
        const { rerender } = render(
            <ActivityLogCard activities={paginated([activity()], 1)} />,
        );

        expect(screen.getByText('1 event')).toBeInTheDocument();

        rerender(
            <ActivityLogCard
                activities={paginated([activity(), activity({ id: 2 })], 2)}
            />,
        );

        expect(screen.getByText('2 events')).toBeInTheDocument();
    });

    it('falls back to "System" when there is no causer', () => {
        render(
            <ActivityLogCard
                activities={paginated([activity({ causer_name: null })], 1)}
            />,
        );

        expect(screen.getByText('System')).toBeInTheDocument();
    });

    it('links to the dossier subject but not to other subject types', () => {
        const { rerender } = render(
            <ActivityLogCard
                activities={paginated(
                    [
                        activity({
                            subject: {
                                type: 'Dossier',
                                id: 7,
                                name: 'Q1 return',
                            },
                        }),
                    ],
                    1,
                )}
            />,
        );

        expect(screen.getByRole('link', { name: 'Q1 return' })).toHaveAttribute(
            'href',
            expect.stringContaining('/dossiers/7'),
        );

        rerender(
            <ActivityLogCard
                activities={paginated(
                    [
                        activity({
                            subject: {
                                type: 'Client',
                                id: 3,
                                name: 'Acme BV',
                            },
                        }),
                    ],
                    1,
                )}
            />,
        );

        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.getByText('Acme BV')).toBeInTheDocument();
    });

    it('renders attribute changes as an old → new diff, deduped across both sides', () => {
        render(
            <ActivityLogCard
                activities={paginated(
                    [
                        activity({
                            attribute_changes: {
                                attributes: { status: 'closed', flagged: true },
                                old: { status: 'open' },
                            },
                        }),
                    ],
                    1,
                )}
            />,
        );

        expect(screen.getByText('status:')).toBeInTheDocument();
        expect(screen.getByText('open')).toBeInTheDocument();
        expect(screen.getByText('closed')).toBeInTheDocument();
        // Boolean values are rendered as the literal string, not "1"/"[object Object]".
        expect(screen.getByText('true')).toBeInTheDocument();
    });

    it('shows ip/route context only when at least one is present', () => {
        const { rerender } = render(
            <ActivityLogCard
                activities={paginated(
                    [
                        activity({
                            properties: {
                                ip: '10.0.0.1',
                                route: 'dossiers.show',
                            },
                        }),
                    ],
                    1,
                )}
            />,
        );

        expect(
            screen.getByText('10.0.0.1 · dossiers.show'),
        ).toBeInTheDocument();

        rerender(
            <ActivityLogCard
                activities={paginated(
                    [activity({ properties: { ip: null, route: null } })],
                    1,
                )}
            />,
        );

        expect(screen.queryByText(/dossiers\.show/)).not.toBeInTheDocument();
    });
});
