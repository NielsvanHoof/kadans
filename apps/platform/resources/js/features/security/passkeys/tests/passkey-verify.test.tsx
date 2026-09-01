import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const visit = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { visit },
}));

const verify = vi.fn();
let hookState: {
    isLoading: boolean;
    error: string | null;
    isSupported: boolean;
};
let registeredOnSuccess:
    | ((response: { redirect?: string }) => void)
    | undefined;

vi.mock('@laravel/passkeys/react', () => ({
    usePasskeyVerify: (opts: {
        onSuccess?: (response: { redirect?: string }) => void;
    }) => {
        registeredOnSuccess = opts?.onSuccess;

        return { verify, ...hookState };
    },
}));

const { default: PasskeyVerify, isFileDownloadUrl } =
    await import('@/features/security/passkeys/passkey-verify');

beforeEach(() => {
    visit.mockReset();
    verify.mockReset();
    registeredOnSuccess = undefined;
    hookState = { isLoading: false, error: null, isSupported: true };
});

describe('isFileDownloadUrl', () => {
    const origin = 'http://localhost';

    it('matches signed staff download paths', () => {
        expect(
            isFileDownloadUrl(
                '/workspaces/acme/dossiers/8/exports/1/download?expires=1&signature=abc',
                origin,
            ),
        ).toBe(true);
    });

    it('does not match in-app pages', () => {
        expect(isFileDownloadUrl('/dashboard', origin)).toBe(false);
        expect(isFileDownloadUrl('/', origin)).toBe(false);
    });
});

describe('PasskeyVerify', () => {
    it('renders nothing when passkeys are not supported', () => {
        hookState = { isLoading: false, error: null, isSupported: false };

        const { container } = render(<PasskeyVerify />);

        expect(container).toBeEmptyDOMElement();
    });

    it('visits an in-app page after a successful passkey', () => {
        render(<PasskeyVerify />);

        registeredOnSuccess?.({ redirect: '/dashboard' });

        expect(visit).toHaveBeenCalledWith('/dashboard');
    });

    it('loads a file download with a full page visit so S3 CORS is not hit', () => {
        const assign = vi.fn();

        vi.stubGlobal('location', {
            assign,
            origin: 'http://localhost',
        });

        const href =
            '/workspaces/acme/dossiers/8/exports/1/download?expires=1&signature=abc';

        render(<PasskeyVerify />);

        registeredOnSuccess?.({ redirect: href });

        expect(assign).toHaveBeenCalledWith(href);
        expect(visit).not.toHaveBeenCalled();

        vi.unstubAllGlobals();
    });

    it('surfaces the hook error via InputError', () => {
        hookState = {
            isLoading: false,
            error: 'That passkey could not be verified.',
            isSupported: true,
        };

        render(<PasskeyVerify />);

        expect(
            screen.getByText('That passkey could not be verified.'),
        ).toBeInTheDocument();
    });
});
