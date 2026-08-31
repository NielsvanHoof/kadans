import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { PipelineStatus } from '@/features/pipelines/types';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: PipelineStatusBadge } =
    await import('@/features/pipelines/status/pipeline-status-badge');

describe('PipelineStatusBadge', () => {
    it.each([
        ['pending', 'Pending', 'open'],
        ['running', 'Running', 'flagged'],
        ['completed', 'Completed', 'ticked'],
        ['failed', 'Failed', 'queried'],
        ['stuck', 'Stuck', 'queried'],
        ['cancelled', 'Cancelled', 'open'],
    ] as [PipelineStatus, string, string][])(
        'renders %s with label and shape mark %s',
        (status, label, mark) => {
            const { unmount } = render(<PipelineStatusBadge status={status} />);

            expect(screen.getByText(label)).toBeInTheDocument();
            expect(
                document.querySelector(`[data-audit-mark="${mark}"]`),
            ).toBeInTheDocument();

            unmount();
        },
    );
});
