import { describe, expect, it } from 'vite-plus/test';
import {
    DOCUMENT_REQUEST_STATUS_LABELS,
    documentRequestStatusLabel,
} from '@/features/document-requests/status/document-request-status';
import type { DocumentRequestStatus } from '@/features/document-requests/types';

const identity = (key: string) => key;

describe('documentRequestStatusLabel', () => {
    it.each(
        Object.keys(DOCUMENT_REQUEST_STATUS_LABELS) as DocumentRequestStatus[],
    )('resolves the label for %s through t()', (status) => {
        expect(documentRequestStatusLabel(status, identity)).toBe(
            DOCUMENT_REQUEST_STATUS_LABELS[status],
        );
    });

    it('maps accepted to Approved for review/portal copy', () => {
        expect(documentRequestStatusLabel('accepted', identity)).toBe(
            'Approved',
        );
    });
});
