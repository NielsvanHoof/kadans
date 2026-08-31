import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vite-plus/test';
import { CardTitle } from '@/components/ui/card';

describe('CardTitle', () => {
    it('renders as an h2 by default', () => {
        render(<CardTitle>Members</CardTitle>);

        const heading = screen.getByRole('heading', {
            level: 2,
            name: 'Members',
        });

        expect(heading.tagName).toBe('H2');
        expect(heading).toHaveAttribute('data-slot', 'card-title');
    });

    it('allows overriding the heading level', () => {
        render(<CardTitle level={3}>Details</CardTitle>);

        expect(
            screen.getByRole('heading', { level: 3, name: 'Details' }).tagName,
        ).toBe('H3');
    });
});
