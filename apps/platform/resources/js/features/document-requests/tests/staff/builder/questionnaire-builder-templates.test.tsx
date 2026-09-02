import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { ApplyTemplateOption } from '@/features/questionnaires/types';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: QuestionnaireBuilderTemplates } =
    await import('@/features/document-requests/staff/builder/questionnaire-builder-templates');

const KYC_TEMPLATE: ApplyTemplateOption = {
    id: 1,
    name: 'KYC onboarding',
    category_label: 'Onboarding',
    items_count: 4,
    is_system: true,
    items: [],
};

describe('QuestionnaireBuilderTemplates', () => {
    it('lets the helper copy wrap without a locked desktop trigger height', () => {
        render(
            <QuestionnaireBuilderTemplates
                templates={[KYC_TEMPLATE]}
                canEdit
                onApply={vi.fn()}
            />,
        );

        const trigger = screen.getByRole('button', {
            name: /Templates/,
        });

        // size="sm" applies md:h-6; both breakpoints must stay auto or the
        // wrapped helper melts into the Template label below.
        expect(trigger.className).toContain('h-auto');
        expect(trigger.className).toContain('md:h-auto');
    });

    it('shows the template picker after expanding the section', async () => {
        const user = userEvent.setup();

        render(
            <QuestionnaireBuilderTemplates
                templates={[KYC_TEMPLATE]}
                canEdit
                onApply={vi.fn()}
            />,
        );

        await user.click(screen.getByRole('button', { name: /Templates/ }));

        expect(screen.getByText('Template')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Apply template' }),
        ).toBeVisible();
    });
});
