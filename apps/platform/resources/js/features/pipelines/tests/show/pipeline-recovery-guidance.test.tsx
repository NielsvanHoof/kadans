import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { PipelineDetail } from '@/features/pipelines/types';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

const { default: PipelineRecoveryGuidance } =
    await import('@/features/pipelines/show/pipeline-recovery-guidance');

function pipeline(overrides: Partial<PipelineDetail> = {}): PipelineDetail {
    return {
        id: 18,
        type: 'ocr_document',
        status: 'failed',
        current_stage: 'submit',
        provider: 'textract',
        provider_job_id: 'job-1',
        attempt_number: 1,
        error_code: 'ocr_job_failed',
        error_message: 'Raw provider exception text',
        metadata: null,
        started_at: '2026-08-17T10:00:00+00:00',
        last_activity_at: '2026-08-17T10:05:00+00:00',
        next_retry_at: null,
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

describe('PipelineRecoveryGuidance', () => {
    it('renders nothing for a completed run', () => {
        const { container } = render(
            <PipelineRecoveryGuidance
                pipeline={pipeline({ status: 'completed', error_code: null })}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('shows retry scheduled with the next try time and keeps the raw message out', () => {
        render(
            <PipelineRecoveryGuidance
                pipeline={pipeline({
                    next_retry_at: '2026-08-17T12:00:00+00:00',
                })}
            />,
        );

        expect(screen.getByText('Retry scheduled')).toBeInTheDocument();
        expect(
            screen.getByText('Reading this document failed.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                "We'll try this job again at 8/17/26, 12:00:00 PM. Use Retry if you want to start now.",
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Raw provider exception text'),
        ).not.toBeInTheDocument();
    });

    it('shows staff-action copy when the automatic budget is spent', () => {
        render(<PipelineRecoveryGuidance pipeline={pipeline()} />);

        expect(screen.getByText('This job needs a retry')).toBeInTheDocument();
        expect(
            screen.getByText(
                'It will not try again on its own. Use Retry to start it again.',
            ),
        ).toBeInTheDocument();
    });

    it('titles stuck and cancelled runs distinctly', () => {
        const { rerender } = render(
            <PipelineRecoveryGuidance
                pipeline={pipeline({
                    status: 'stuck',
                    error_code: 'pipeline_inactive',
                })}
            />,
        );

        expect(screen.getByText('This job is stuck')).toBeInTheDocument();

        rerender(
            <PipelineRecoveryGuidance
                pipeline={pipeline({
                    status: 'cancelled',
                    error_code: null,
                    error_message: null,
                })}
            />,
        );

        expect(screen.getByText('This job was cancelled')).toBeInTheDocument();
    });

    it('omits the localised sentence for an unknown error code', () => {
        render(
            <PipelineRecoveryGuidance
                pipeline={pipeline({
                    error_code: 'textract_SOMETHING_NEW',
                    next_retry_at: '2026-08-17T12:00:00+00:00',
                })}
            />,
        );

        expect(screen.getByText('Retry scheduled')).toBeInTheDocument();
        expect(
            screen.queryByText('Reading this document failed.'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Raw provider exception text'),
        ).not.toBeInTheDocument();
    });
});
