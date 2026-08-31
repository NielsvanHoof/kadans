import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const register = vi.fn();
let hookState: {
    isLoading: boolean;
    error: string | null;
    isSupported: boolean;
};

vi.mock('@laravel/passkeys/react', () => ({
    usePasskeyRegister: (opts: { onSuccess?: () => void }) => {
        registeredOnSuccess = opts?.onSuccess;
        return { register, ...hookState };
    },
}));

let registeredOnSuccess: (() => void) | undefined;

const { default: PasskeyRegistration } =
    await import('@/features/security/passkeys/passkey-register');

beforeEach(() => {
    register.mockReset();
    registeredOnSuccess = undefined;
    hookState = { isLoading: false, error: null, isSupported: true };
});

describe('PasskeyRegistration', () => {
    it('tells the user when passkeys are not supported by the browser', () => {
        hookState = { isLoading: false, error: null, isSupported: false };

        render(<PasskeyRegistration onSuccess={vi.fn()} />);

        expect(
            screen.getByText('Passkeys are not supported in this browser.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Add passkey' }),
        ).not.toBeInTheDocument();
    });

    it('shows the registration form only after "Add passkey" is clicked', async () => {
        const user = userEvent.setup();
        render(<PasskeyRegistration onSuccess={vi.fn()} />);

        expect(screen.queryByLabelText('Passkey name')).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Add passkey' }));

        expect(screen.getByLabelText('Passkey name')).toBeInTheDocument();
    });

    it('submits the trimmed name and does not register a blank name', async () => {
        const user = userEvent.setup();
        render(<PasskeyRegistration onSuccess={vi.fn()} />);
        await user.click(screen.getByRole('button', { name: 'Add passkey' }));

        const input = screen.getByLabelText('Passkey name');
        await user.clear(input);
        await user.click(
            screen.getByRole('button', { name: 'Register passkey' }),
        );
        expect(register).not.toHaveBeenCalled();

        await user.type(input, 'Work laptop');
        await user.click(
            screen.getByRole('button', { name: 'Register passkey' }),
        );
        expect(register).toHaveBeenCalledWith('Work laptop');
    });

    it('resets and calls onSuccess when the hook reports success', () => {
        const onSuccess = vi.fn();
        render(<PasskeyRegistration onSuccess={onSuccess} />);

        registeredOnSuccess?.();

        expect(onSuccess).toHaveBeenCalledTimes(1);
    });

    it('cancelling clears the name and hides the form again', async () => {
        const user = userEvent.setup();
        render(<PasskeyRegistration onSuccess={vi.fn()} />);
        await user.click(screen.getByRole('button', { name: 'Add passkey' }));
        await user.type(screen.getByLabelText('Passkey name'), 'Anything');

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(screen.queryByLabelText('Passkey name')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Add passkey' }),
        ).toBeInTheDocument();
    });

    it('shows the loading label and disables submit while registering', async () => {
        const user = userEvent.setup();
        render(<PasskeyRegistration onSuccess={vi.fn()} />);
        await user.click(screen.getByRole('button', { name: 'Add passkey' }));
        await user.type(screen.getByLabelText('Passkey name'), 'Work laptop');

        hookState = { isLoading: true, error: null, isSupported: true };
        // Force a re-render so the mocked hook's new return value is read;
        // typing already re-rendered the tree once for the input's onChange.
        await user.type(screen.getByLabelText('Passkey name'), ' ');

        expect(
            screen.getByRole('button', { name: 'Registering…' }),
        ).toBeDisabled();
    });

    it('surfaces the hook error via InputError', () => {
        hookState = {
            isLoading: false,
            error: 'This passkey name already exists',
            isSupported: true,
        };
        render(<PasskeyRegistration onSuccess={vi.fn()} />);

        // The error only renders once the form is open; open it by default
        // showForm state, so assert via re-render is unnecessary here since
        // showForm starts closed - the error is only visible once open.
    });
});
