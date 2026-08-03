import ClientPortalUploadController from '@/actions/App/ClientPortal/Http/Controllers/ClientPortalUploadController';
import DocumentFileUploadForm from '@/features/document-requests/document-file-upload-form';
import type { PortalDocumentRequest } from '@/features/document-requests/types';
import { inlineActionOptions } from '@/lib/inline-action-options';

/**
 * Client-portal file upload for a single document request.
 * Uses the restricted client portal session, not staff auth.
 */
export default function PortalDocumentUpload({
    documentRequest,
}: {
    documentRequest: PortalDocumentRequest;
}) {
    return (
        <DocumentFileUploadForm
            inputId={`document-${documentRequest.id}`}
            hasExistingUpload={documentRequest.uploaded_document !== null}
            actionUrl={ClientPortalUploadController.store.url({
                documentRequest: documentRequest.id,
            })}
            visitOptions={inlineActionOptions}
            className="mt-3 space-y-2"
        />
    );
}
