import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { PipelineDetail } from '@/features/pipelines/types';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: PipelineShowDiagnostics } =
    await import('@/features/pipelines/show/pipeline-show-diagnostics');

function pipeline(overrides: Partial<PipelineDetail> = {}): PipelineDetail {
    return {
        id: 18,
        type: 'ocr_document',
        status: 'failed',
        current_stage: 'submit',
        provider: 'textract',
        provider_job_id: 'job-abc',
        attempt_number: 2,
        error_code: 'ocr_job_failed',
        error_message: 'Raw provider exception text',
        metadata: { region: 'eu-west-1' },
        started_at: '2026-08-17T10:00:00+00:00',
        last_activity_at: '2026-08-17T10:05:00+00:00',
        next_retry_at: '2026-08-17T12:00:00+00:00',
        completed_at: null,
        failed_at: '2026-08-17T10:05:00+00:00',
        steps: [],
        subject: {
            type: 'UploadedDocument',
            id: 12,
            label: 'annual-accounts.pdf',
            context: null,
            href: null,
        },
        ...overrides,
    };
}

describe('PipelineShowDiagnostics', () => {
    it('keeps provider identifiers and raw error detail', () => {
        render(<PipelineShowDiagnostics pipeline={pipeline()} />);

        expect(
            screen.getByText(
                'Provider identifiers and the raw error for this run.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('textract')).toBeInTheDocument();
        expect(screen.getByText('job-abc')).toBeInTheDocument();
        expect(screen.getByText('ocr_job_failed')).toBeInTheDocument();
        expect(
            screen.getByText('Raw provider exception text'),
        ).toBeInTheDocument();
        expect(screen.getByText(/"region": "eu-west-1"/)).toBeInTheDocument();
    });

    it('does not show next_retry_at or the localised failure sentence', () => {
        render(<PipelineShowDiagnostics pipeline={pipeline()} />);

        expect(screen.queryByText('Next retry')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Reading this document failed.'),
        ).not.toBeInTheDocument();
    });
});
