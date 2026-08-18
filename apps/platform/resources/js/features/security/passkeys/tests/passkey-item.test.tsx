import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { mockUseTranslation } from '@/test/mocks';
import type { Passkey } from '@/types/auth';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: PasskeyItem } =
    await import('@/features/security/passkeys/passkey-item');

function passkey(overrides: Partial<Passkey> = {}): Passkey {
    return {
        id: 1,
        name: 'MacBook Pro',
        authenticator: null,
        created_at_diff: '2 days ago',
        last_used_at_diff: null,
        ...overrides,
    };
}

describe('PasskeyItem', () => {
    it('renders the name, added date, and authenticator badge when present', () => {
        render(
            <PasskeyItem
                passkey={passkey({ authenticator: 'iCloud Keychain' })}
                onDelete={vi.fn()}
            />,
        );

        expect(screen.getByText('MacBook Pro')).toBeInTheDocument();
        expect(screen.getByText('iCloud Keychain')).toBeInTheDocument();
        expect(screen.getByText('Added :when')).toBeInTheDocument();
    });

    it('shows the last-used line only when the passkey has been used', () => {
        const { rerender } = render(
            <PasskeyItem
                passkey={passkey({ last_used_at_diff: null })}
                onDelete={vi.fn()}
            />,
        );
        expect(screen.queryByText(/Last used :when/)).not.toBeInTheDocument();

        rerender(
            <PasskeyItem
                passkey={passkey({ last_used_at_diff: '1 hour ago' })}
                onDelete={vi.fn()}
            />,
        );
        expect(screen.getByText(/Last used :when/)).toBeInTheDocument();
    });

    it('confirms via the alert dialog before calling onDelete', async () => {
        const user = userEvent.setup();
        const onDelete = vi.fn();

        render(
            <PasskeyItem passkey={passkey({ id: 7 })} onDelete={onDelete} />,
        );

        await user.click(screen.getByRole('button', { name: 'Remove' }));
        await user.click(
            screen.getByRole('button', { name: 'Remove passkey' }),
        );

        expect(onDelete).toHaveBeenCalledWith(7, expect.any(Function));
    });

    it('disables the confirm action and swaps its label while deleting', async () => {
        const user = userEvent.setup();
        let capturedOnError: (() => void) | undefined;
        const onDelete = vi.fn((_id: number, onError: () => void) => {
            capturedOnError = onError;
        });

        render(<PasskeyItem passkey={passkey()} onDelete={onDelete} />);

        await user.click(screen.getByRole('button', { name: 'Remove' }));
        await user.click(
            screen.getByRole('button', { name: 'Remove passkey' }),
        );

        expect(
            screen.getByRole('button', { name: 'Removing…' }),
        ).toBeDisabled();

        // Simulate the delete failing: the item must recover from isDeleting.
        act(() => {
            capturedOnError?.();
        });
        expect(
            screen.getByRole('button', { name: 'Remove passkey' }),
        ).not.toBeDisabled();
    });
});
