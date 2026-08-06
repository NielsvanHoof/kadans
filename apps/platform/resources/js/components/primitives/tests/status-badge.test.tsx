import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import StatusBadge from '@/components/primitives/status-badge';

describe('StatusBadge', () => {
    it('renders the label with an audit mark shape cue', () => {
        render(
            <StatusBadge
                mark="ticked"
                label="Completed"
                statusClassName="border-success-border bg-success-muted"
            />,
        );

        expect(screen.getByText('Completed')).toBeInTheDocument();
        expect(
            document.querySelector('[data-audit-mark="ticked"]'),
        ).toBeInTheDocument();
    });

    it('distinguishes statuses by mark shape without relying on colour classes', () => {
        const { rerender } = render(
            <StatusBadge
                mark="open"
                label="Pending"
                statusClassName="text-red-500"
            />,
        );

        expect(
            document.querySelector('[data-audit-mark="open"]'),
        ).toBeInTheDocument();

        rerender(
            <StatusBadge
                mark="queried"
                label="Failed"
                statusClassName="text-red-500"
            />,
        );

        expect(
            document.querySelector('[data-audit-mark="queried"]'),
        ).toBeInTheDocument();
        expect(
            document.querySelector('[data-audit-mark="open"]'),
        ).not.toBeInTheDocument();
    });
});
