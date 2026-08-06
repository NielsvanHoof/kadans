import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: SkipToMainContent } =
    await import('@/components/primitives/skip-to-main-content');

describe('SkipToMainContent', () => {
    it('is the first tab stop and targets main content', async () => {
        const user = userEvent.setup();

        render(
            <div>
                <SkipToMainContent />
                <button type="button">Later control</button>
                <main id="main-content" tabIndex={-1}>
                    Content
                </main>
            </div>,
        );

        await user.tab();

        const skipLink = screen.getByRole('link', { name: 'Skip to content' });

        expect(skipLink).toHaveFocus();
        expect(skipLink).toHaveAttribute('href', '#main-content');
        expect(skipLink.className).toContain('sr-only');
        expect(skipLink.className).toContain('focus:not-sr-only');
    });
});
