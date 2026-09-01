import type { UploadedDocument } from '@/features/document-requests/types';

function isOcrInFlight(uploadedDocument: UploadedDocument): boolean {
    return (
        uploadedDocument.processing_status === 'pending' ||
        uploadedDocument.processing_status === 'processing'
    );
}

export function isWaitingForTextract(
    uploadedDocument: UploadedDocument,
): boolean {
    return (
        isOcrInFlight(uploadedDocument) &&
        uploadedDocument.pipeline?.current_stage === 'provider_processing'
    );
}

export function ocrProcessingMessage(
    uploadedDocument: UploadedDocument,
): string {
    if (isWaitingForTextract(uploadedDocument)) {
        return 'Still scanning your document. This usually takes a few minutes — results will appear when ready.';
    }

    return 'Analyzing document structure (forms and tables)…';
}

export function ocrRunningBannerMessage(
    uploadedDocument: UploadedDocument,
): string {
    if (isWaitingForTextract(uploadedDocument)) {
        return 'Still scanning your document. This usually takes a few minutes — results will appear when ready.';
    }

    return 'Analysis is still running. Results will update shortly.';
}

export function ocrReviewBannerMessage(
    uploadedDocument: UploadedDocument,
): string {
    if (isWaitingForTextract(uploadedDocument)) {
        return 'Still scanning your document. This usually takes a few minutes — results will appear when ready.';
    }

    return 'Document analysis is still running. Results will update shortly.';
}
