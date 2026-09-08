import UploadedDocumentController from '@/actions/App/Dossiers/Http/Controllers/UploadedDocuments/UploadedDocumentController';
import UploadTicketController from '@/actions/App/Dossiers/Http/Controllers/Uploads/UploadTicketController';
import DocumentFileUploadForm from '@/features/document-requests/document-file-upload-form';
import type { DocumentRequest } from '@/features/document-requests/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { inlineActionOptions } from '@/lib/inline-action-options';

/**
 * Staff fallback file upload (email / walk-in).
 * Shown only after expanding “Upload/Replace on behalf of client”.
 */
export default function DocumentRequestUpload({
    dossierId,
    documentRequest,
}: {
    dossierId: number;
    documentRequest: DocumentRequest;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const allowMultiple =
        documentRequest.configuration?.allow_multiple ?? false;

    return (
        <DocumentFileUploadForm
            inputId={`document-${documentRequest.id}`}
            hasExistingUpload={documentRequest.uploaded_documents.length > 0}
            allowMultiple={allowMultiple}
            actionUrl={UploadedDocumentController.store.url({
                tenant: currentWorkspace,
                dossier: dossierId,
                documentRequest: documentRequest.id,
            })}
            signUrl={UploadTicketController.store.url({
                tenant: currentWorkspace,
            })}
            visitOptions={inlineActionOptions}
        />
    );
}
