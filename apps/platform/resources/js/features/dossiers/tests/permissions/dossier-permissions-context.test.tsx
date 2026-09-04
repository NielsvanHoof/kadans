import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vite-plus/test';
import {
    type DossierCapabilitiesInput,
    DossierPermissionsProvider,
    deriveDossierCapabilities,
    useDossierCapabilities,
} from '@/features/dossiers/permissions/dossier-permissions-context';

function input(
    overrides: Partial<DossierCapabilitiesInput> = {},
): DossierCapabilitiesInput {
    return {
        canManage: true,
        canReview: true,
        canDownload: true,
        canEditDetails: true,
        canSendReminder: true,
        canUploadAudio: true,
        canDownloadAudio: true,
        canRollOver: true,
        dossierStatus: 'draft',
        hasFormItems: true,
        ...overrides,
    };
}

describe('deriveDossierCapabilities', () => {
    it('passes through the flags that do not depend on dossier status', () => {
        const capabilities = deriveDossierCapabilities(
            input({
                canManage: false,
                canDownload: false,
                canEditDetails: false,
                canSendReminder: false,
                canUploadAudio: false,
                canDownloadAudio: false,
            }),
        );

        expect(capabilities.canManage).toBe(false);
        expect(capabilities.canDownload).toBe(false);
        expect(capabilities.canEditDetails).toBe(false);
        expect(capabilities.canSendReminder).toBe(false);
        expect(capabilities.canUploadAudio).toBe(false);
        expect(capabilities.canDownloadAudio).toBe(false);
    });

    it('disables review once the dossier is completed even if canReview is true', () => {
        const capabilities = deriveDossierCapabilities(
            input({ canReview: true, dossierStatus: 'completed' }),
        );

        expect(capabilities.canReview).toBe(false);
    });

    it('keeps review enabled for a non-completed dossier', () => {
        const capabilities = deriveDossierCapabilities(
            input({ canReview: true, dossierStatus: 'in_review' }),
        );

        expect(capabilities.canReview).toBe(true);
    });

    it('never grants review without the base permission, regardless of status', () => {
        const capabilities = deriveDossierCapabilities(
            input({ canReview: false, dossierStatus: 'draft' }),
        );

        expect(capabilities.canReview).toBe(false);
    });

    it('disables structure edits (staff upload fallback, invite) once completed', () => {
        const capabilities = deriveDossierCapabilities(
            input({
                canManage: true,
                hasFormItems: true,
                dossierStatus: 'completed',
            }),
        );

        expect(capabilities.canStaffUploadFallback).toBe(false);
        expect(capabilities.canCreateInvite).toBe(false);
    });

    it('disables structure edits without canManage even when not completed', () => {
        const capabilities = deriveDossierCapabilities(
            input({ canManage: false, dossierStatus: 'draft' }),
        );

        expect(capabilities.canStaffUploadFallback).toBe(false);
        expect(capabilities.canCreateInvite).toBe(false);
    });

    it('only allows creating an invite once the questionnaire has items', () => {
        const withoutItems = deriveDossierCapabilities(
            input({
                canManage: true,
                dossierStatus: 'draft',
                hasFormItems: false,
            }),
        );
        const withItems = deriveDossierCapabilities(
            input({
                canManage: true,
                dossierStatus: 'draft',
                hasFormItems: true,
            }),
        );

        expect(withoutItems.canCreateInvite).toBe(false);
        expect(withItems.canCreateInvite).toBe(true);
        // Staff upload fallback does not require form items, unlike invites.
        expect(withoutItems.canStaffUploadFallback).toBe(true);
    });

    it('carries the dossier status through unchanged', () => {
        const capabilities = deriveDossierCapabilities(
            input({ dossierStatus: 'awaiting_client' }),
        );

        expect(capabilities.dossierStatus).toBe('awaiting_client');
    });
});

describe('useDossierCapabilities', () => {
    it('throws when used outside DossierPermissionsProvider', () => {
        expect(() => renderHook(() => useDossierCapabilities())).toThrow(
            'useDossierCapabilities must be used within DossierPermissionsProvider.',
        );
    });

    it('exposes derived capabilities computed from the provider props', () => {
        const { result } = renderHook(() => useDossierCapabilities(), {
            wrapper: ({ children }) => (
                <DossierPermissionsProvider {...input({ canManage: false })}>
                    {children}
                </DossierPermissionsProvider>
            ),
        });

        expect(result.current.canManage).toBe(false);
        expect(result.current.canStaffUploadFallback).toBe(false);
        expect(result.current.dossierStatus).toBe('draft');
    });
});
