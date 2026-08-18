import { describe, expect, it } from 'vitest';
import {
    DOCUMENT_PROCESSING_STATUS_LABELS,
    documentProcessingStatusLabel,
} from '@/features/document-requests/status/document-processing-status';
import type { DocumentProcessingStatus } from '@/features/document-requests/types';

const identity = (key: string) => key;

describe('documentProcessingStatusLabel', () => {
    it.each(
        Object.keys(
            DOCUMENT_PROCESSING_STATUS_LABELS,
        ) as DocumentProcessingStatus[],
    )('resolves the label for %s through t()', (status) => {
        expect(documentProcessingStatusLabel(status, identity)).toBe(
            DOCUMENT_PROCESSING_STATUS_LABELS[status],
        );
    });
});
