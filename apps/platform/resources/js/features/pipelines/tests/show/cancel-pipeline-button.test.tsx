import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import { mockInertiaPage, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () =>
        mockInertiaPage({
            current_firm: { id: 1, slug: 'acme', name: 'Acme' },
        }),
}));

const { default: CancelPipelineButton } =
    await import('@/features/pipelines/show/cancel-pipeline-button');

describe('CancelPipelineButton', () => {
    it('renders nothing when the server says the run may not be cancelled', () => {
        const { container } = render(
            <CancelPipelineButton pipelineId={42} canCancel={false} />,
        );

        expect(container).toBeEmptyDOMElement();
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('renders the trigger while the run is still open', () => {
        render(<CancelPipelineButton pipelineId={42} canCancel />);

        expect(
            screen.getByRole('button', { name: 'Cancel run' }),
        ).toBeInTheDocument();
    });

    it('targets the cancel route for this pipeline', async () => {
        const { cancel } = await import('@/routes/workspaces/pipelines');

        expect(cancel.form({ tenant: 'acme', pipeline: 42 })).toMatchObject({
            action: '/workspaces/acme/pipelines/42/cancel',
            method: 'post',
        });
    });
});
