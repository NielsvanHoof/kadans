import ClientPortalUploadController from '@/actions/App/ClientPortal/Http/Controllers/ClientPortalUploadController';
import ClientPortalUploadTicketController from '@/actions/App/ClientPortal/Http/Controllers/ClientPortalUploadTicketController';
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
    const allowMultiple =
        documentRequest.configuration?.allow_multiple ?? false;

    return (
        <DocumentFileUploadForm
            inputId={`document-${documentRequest.id}`}
            hasExistingUpload={documentRequest.uploaded_documents.length > 0}
            allowMultiple={allowMultiple}
            actionUrl={ClientPortalUploadController.store.url({
                documentRequest: documentRequest.id,
            })}
            signUrl={ClientPortalUploadTicketController.store.url()}
            visitOptions={inlineActionOptions}
            className="mt-3 space-y-2"
        />
    );
}
