import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () =>
        mockInertiaPage({
            current_firm: { id: 1, slug: 'acme', name: 'Acme' },
        }),
}));

const { default: RetryPipelineButton } =
    await import('@/features/pipelines/show/retry-pipeline-button');

describe('RetryPipelineButton', () => {
    it('renders nothing when the server says the run may not be retried', () => {
        const { container } = render(
            <RetryPipelineButton pipelineId={42} canRetry={false} />,
        );

        expect(container).toBeEmptyDOMElement();
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('renders the trigger when the run may be retried', () => {
        render(<RetryPipelineButton pipelineId={42} canRetry />);

        expect(
            screen.getByRole('button', { name: 'Retry' }),
        ).toBeInTheDocument();
    });

    it('targets the retry route for this pipeline', async () => {
        const { retry } = await import('@/routes/workspaces/pipelines');

        expect(retry.form({ tenant: 'acme', pipeline: 42 })).toMatchObject({
            action: '/workspaces/acme/pipelines/42/retry',
            method: 'post',
        });
    });
});
