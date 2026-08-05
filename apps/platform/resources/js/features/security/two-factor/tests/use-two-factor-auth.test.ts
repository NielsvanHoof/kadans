import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const submit = vi.fn();

// The hook's only collaborator is Inertia's useHttp; route helpers are plain
// Wayfinder-generated URL builders and are safe to leave real.
vi.mock('@inertiajs/react', () => ({
    useHttp: () => ({ submit }),
}));

const { useTwoFactorAuth } =
    await import('@/features/security/two-factor/use-two-factor-auth');

beforeEach(() => {
    submit.mockReset();
});

describe('useTwoFactorAuth', () => {
    it('starts with no setup data and no errors', () => {
        const { result } = renderHook(() => useTwoFactorAuth());

        expect(result.current.qrCodeSvg).toBeNull();
        expect(result.current.manualSetupKey).toBeNull();
        expect(result.current.recoveryCodesList).toEqual([]);
        expect(result.current.hasSetupData).toBe(false);
        expect(result.current.errors).toEqual([]);
    });

    describe('fetchSetupData', () => {
        it('populates the QR code and setup key once both requests resolve', async () => {
            submit
                .mockResolvedValueOnce({ svg: '<svg />', url: '/qr' })
                .mockResolvedValueOnce({ secretKey: 'ABCD1234' });
            const { result } = renderHook(() => useTwoFactorAuth());

            await act(async () => {
                await result.current.fetchSetupData();
            });

            expect(result.current.qrCodeSvg).toBe('<svg />');
            expect(result.current.manualSetupKey).toBe('ABCD1234');
            expect(result.current.hasSetupData).toBe(true);
            expect(result.current.errors).toEqual([]);
        });

        it('records one error per failed request but keeps the successful half', async () => {
            submit
                .mockRejectedValueOnce(new Error('qr failed'))
                .mockResolvedValueOnce({ secretKey: 'ABCD1234' });
            const { result } = renderHook(() => useTwoFactorAuth());

            await act(async () => {
                await result.current.fetchSetupData();
            });

            expect(result.current.qrCodeSvg).toBeNull();
            expect(result.current.manualSetupKey).toBe('ABCD1234');
            expect(result.current.errors).toEqual(['Failed to fetch QR code']);
            expect(result.current.hasSetupData).toBe(false);
        });
    });

    describe('fetchRecoveryCodes', () => {
        it('replaces the recovery codes list on success', async () => {
            submit.mockResolvedValueOnce(['code-1', 'code-2']);
            const { result } = renderHook(() => useTwoFactorAuth());

            await act(async () => {
                await result.current.fetchRecoveryCodes();
            });

            expect(result.current.recoveryCodesList).toEqual([
                'code-1',
                'code-2',
            ]);
            expect(result.current.errors).toEqual([]);
        });

        it('clears the list and reports an error on failure', async () => {
            submit.mockRejectedValueOnce(new Error('network'));
            const { result } = renderHook(() => useTwoFactorAuth());

            await act(async () => {
                await result.current.fetchRecoveryCodes();
            });

            expect(result.current.recoveryCodesList).toEqual([]);
            expect(result.current.errors).toEqual([
                'Failed to fetch recovery codes',
            ]);
        });
    });

    describe('clearing state', () => {
        it('clearSetupData resets the QR/key and errors but keeps recovery codes', async () => {
            submit
                .mockResolvedValueOnce({ svg: '<svg />', url: '/qr' })
                .mockResolvedValueOnce({ secretKey: 'ABCD1234' })
                .mockResolvedValueOnce(['code-1']);
            const { result } = renderHook(() => useTwoFactorAuth());

            await act(async () => {
                await result.current.fetchSetupData();
                await result.current.fetchRecoveryCodes();
            });

            act(() => {
                result.current.clearSetupData();
            });

            expect(result.current.qrCodeSvg).toBeNull();
            expect(result.current.manualSetupKey).toBeNull();
            expect(result.current.errors).toEqual([]);
            expect(result.current.recoveryCodesList).toEqual(['code-1']);
        });

        it('clearTwoFactorAuthData resets everything including recovery codes', async () => {
            submit
                .mockResolvedValueOnce({ svg: '<svg />', url: '/qr' })
                .mockResolvedValueOnce({ secretKey: 'ABCD1234' })
                .mockResolvedValueOnce(['code-1']);
            const { result } = renderHook(() => useTwoFactorAuth());

            await act(async () => {
                await result.current.fetchSetupData();
                await result.current.fetchRecoveryCodes();
            });

            act(() => {
                result.current.clearTwoFactorAuthData();
            });

            expect(result.current.qrCodeSvg).toBeNull();
            expect(result.current.manualSetupKey).toBeNull();
            expect(result.current.recoveryCodesList).toEqual([]);
            expect(result.current.errors).toEqual([]);
        });

        it('clearErrors only resets errors', async () => {
            submit.mockRejectedValueOnce(new Error('network'));
            const { result } = renderHook(() => useTwoFactorAuth());

            await act(async () => {
                await result.current.fetchRecoveryCodes();
            });
            await waitFor(() =>
                expect(result.current.errors.length).toBeGreaterThan(0),
            );

            act(() => {
                result.current.clearErrors();
            });

            expect(result.current.errors).toEqual([]);
        });
    });
});
