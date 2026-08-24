import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: DaysRemainingLabel } =
    await import('@/components/primitives/days-remaining-label');

/**
 * The day count arrives signed from the server so the browser never redoes date
 * maths. These cases pin the boundaries either side of zero, where an
 * off-by-one would quietly turn "due today" into "1 day overdue".
 */
describe('DaysRemainingLabel', () => {
    it('names today rather than counting zero days', () => {
        render(<DaysRemainingLabel daysRemaining={0} />);

        expect(screen.getByText('Due today')).toBeInTheDocument();
    });

    it('names tomorrow rather than counting one day', () => {
        render(<DaysRemainingLabel daysRemaining={1} />);

        expect(screen.getByText('Due tomorrow')).toBeInTheDocument();
    });

    it('counts days for anything further out', () => {
        render(<DaysRemainingLabel daysRemaining={5} />);

        expect(screen.getByText('Due in :count days')).toBeInTheDocument();
    });

    it('reads a negative count as overdue, not as a negative due date', () => {
        render(<DaysRemainingLabel daysRemaining={-3} />);

        expect(screen.getByText(':count days overdue')).toBeInTheDocument();
        expect(screen.queryByText(/Due in/)).not.toBeInTheDocument();
    });

    it('uses the singular form one day past the due date', () => {
        render(<DaysRemainingLabel daysRemaining={-1} />);

        expect(screen.getByText('1 day overdue')).toBeInTheDocument();
    });

    it('marks overdue with an icon as well as a colour', () => {
        const { container } = render(<DaysRemainingLabel daysRemaining={-2} />);

        // Colour alone must not carry status.
        expect(container.querySelector('svg')).toBeInTheDocument();
    });

    it('does not decorate a future due date with the overdue icon', () => {
        const { container } = render(<DaysRemainingLabel daysRemaining={4} />);

        expect(container.querySelector('svg')).not.toBeInTheDocument();
    });
});
