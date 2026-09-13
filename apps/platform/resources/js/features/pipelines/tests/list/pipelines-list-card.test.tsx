import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { PipelineIndexRow } from '@/features/pipelines/types';
import {
    mockInertiaPage,
    mockUseFormatters,
    mockUseTranslation,
} from '@/test/mocks';
import type { Paginated } from '@/types/pagination';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());
vi.mock('@/hooks/use-current-workspace', () => ({
    useCurrentWorkspace: () => ({ slug: 'acme', name: 'Acme' }),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () =>
        mockInertiaPage({ current_firm: { slug: 'acme', name: 'Acme' } }),
    Link: ({
        href,
        children,
        className,
        'aria-label': ariaLabel,
    }: {
        href: string | { url: string };
        children: ReactNode;
        className?: string;
        'aria-label'?: string;
    }) => (
        <a
            href={typeof href === 'string' ? href : href.url}
            className={className}
            aria-label={ariaLabel}
        >
            {children}
        </a>
    ),
}));

const { default: PipelinesListCard } =
    await import('@/features/pipelines/list/pipelines-list-card');

function row(overrides: Partial<PipelineIndexRow> = {}): PipelineIndexRow {
    return {
        id: 7,
        type: 'ocr_document',
        status: 'failed',
        current_stage: 'submit',
        attempt_number: 1,
        last_activity_at: '2026-08-17T10:00:00+00:00',
        created_at: '2026-08-17T09:00:00+00:00',
        subject_type: 'UploadedDocument',
        subject_id: 12,
        subject: {
            type: 'UploadedDocument',
            id: 12,
            label: 'annual-accounts.pdf',
            context: 'Acme Corp · Annual audit',
            href: null,
        },
        ...overrides,
    };
}

function paginated(data: PipelineIndexRow[]): Paginated<PipelineIndexRow> {
    return {
        data,
        total: data.length,
    } as Paginated<PipelineIndexRow>;
}

describe('PipelinesListCard', () => {
    it('shows the document, client and dossier without opening the run', () => {
        render(<PipelinesListCard pipelines={paginated([row()])} />);

        expect(screen.getByText('annual-accounts.pdf')).toBeInTheDocument();
        expect(
            screen.getByText('Acme Corp · Annual audit'),
        ).toBeInTheDocument();
    });

    it('keeps a missing subject identifiable', () => {
        render(
            <PipelinesListCard
                pipelines={paginated([
                    row({
                        subject: {
                            type: 'UploadedDocument',
                            id: 12,
                            label: null,
                            context: null,
                            href: null,
                        },
                    }),
                ])}
            />,
        );

        expect(screen.getByText('UploadedDocument #12')).toBeInTheDocument();
        expect(
            screen.queryByText('Acme Corp · Annual audit'),
        ).not.toBeInTheDocument();
    });
});
