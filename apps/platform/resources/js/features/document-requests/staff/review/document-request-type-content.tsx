import { type ReactNode, useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import DocumentProcessingPanel from '@/features/document-requests/staff/review/document-processing-panel';
import DocumentRequestAnswer from '@/features/document-requests/staff/review/document-request-answer';
import DocumentRequestUpload from '@/features/document-requests/staff/review/document-request-upload';
import type { DocumentRequest } from '@/features/document-requests/types';
import { useDossierCapabilities } from '@/features/dossiers/permissions/dossier-permissions-context';
import type { DossierStatus } from '@/features/dossiers/types';
import { useTranslation } from '@/hooks/use-translation';

export type StaffDocumentRequestTypeProps = {
    dossierId: number;
    documentRequest: DocumentRequest;
};

function useStaffFallbackAllowed(documentRequest: DocumentRequest): boolean {
    const { canStaffUploadFallback } = useDossierCapabilities();

    return canStaffUploadFallback && documentRequest.status !== 'accepted';
}

/**
 * File requests: OCR/review first; staff upload is a collapsed fallback.
 */
export function StaffFileRequestContent({
    dossierId,
    documentRequest,
}: StaffDocumentRequestTypeProps) {
    const { canDownload, dossierStatus } = useDossierCapabilities();
    const staffFallbackAllowed = useStaffFallbackAllowed(documentRequest);
    const hasUpload = documentRequest.uploaded_documents.length > 0;
    const allowMultiple =
        documentRequest.configuration?.allow_multiple ?? false;

    return (
        <>
            {documentRequest.uploaded_documents.map((uploadedDocument) => (
                <DocumentProcessingPanel
                    key={uploadedDocument.id}
                    uploadedDocument={uploadedDocument}
                    canDownload={canDownload}
                    canRemove={allowMultiple && uploadedDocument.can_delete}
                />
            ))}

            {!hasUpload && (
                <WaitingForClientUpload dossierStatus={dossierStatus} />
            )}

            {staffFallbackAllowed ? (
                <StaffUploadOnBehalfFallback
                    dossierId={dossierId}
                    documentRequest={documentRequest}
                    hasUpload={hasUpload}
                />
            ) : null}
        </>
    );
}

/**
 * Quiet empty state — clients should upload via the portal.
 */
function WaitingForClientUpload({
    dossierStatus,
}: {
    dossierStatus: DossierStatus;
}) {
    const { t } = useTranslation();

    return (
        <div className="bg-muted/50 text-muted-foreground rounded-md px-3 py-2 text-sm">
            <p>{t('Waiting for the client to upload via the portal.')}</p>
            {dossierStatus === 'draft' && (
                <p className="mt-1">
                    {t(
                        'Invite the client from Client Access so they can upload securely.',
                    )}
                </p>
            )}
        </div>
    );
}

/**
 * Staff upload stays available for email / walk-in, but is not the primary CTA.
 */
function StaffUploadOnBehalfFallback({
    dossierId,
    documentRequest,
    hasUpload,
}: {
    dossierId: number;
    documentRequest: DocumentRequest;
    hasUpload: boolean;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const triggerLabel = hasUpload
        ? t('Replace on behalf of client')
        : t('Upload on behalf of client');

    return (
        <Collapsible
            isExpanded={open}
            onExpandedChange={setOpen}
            className="mt-2"
        >
            <CollapsibleTrigger
                type="button"
                variant="ghost"
                size="sm"
                className="px-0"
            >
                {open ? t('Hide staff upload') : triggerLabel}
            </CollapsibleTrigger>
            <CollapsibleContent>
                <DocumentRequestUpload
                    dossierId={dossierId}
                    documentRequest={documentRequest}
                />
            </CollapsibleContent>
        </Collapsible>
    );
}

/**
 * Text answers are rendered without knowing about other request types.
 */
export function StaffTextRequestContent({
    dossierId,
    documentRequest,
}: StaffDocumentRequestTypeProps) {
    const staffFallbackAllowed = useStaffFallbackAllowed(documentRequest);

    return (
        <>
            {documentRequest.answer_text ? (
                <AnswerPreview>
                    {/* Answer text is user-generated — do not translate. */}
                    <p className="font-medium whitespace-pre-wrap">
                        {documentRequest.answer_text}
                    </p>
                </AnswerPreview>
            ) : null}
            {staffFallbackAllowed ? (
                <DocumentRequestAnswer
                    dossierId={dossierId}
                    documentRequest={documentRequest}
                />
            ) : null}
        </>
    );
}

/**
 * Boolean answers preserve false as a submitted answer.
 */
export function StaffBooleanRequestContent({
    dossierId,
    documentRequest,
}: StaffDocumentRequestTypeProps) {
    const { t } = useTranslation();
    const staffFallbackAllowed = useStaffFallbackAllowed(documentRequest);

    return (
        <>
            {documentRequest.answer_boolean !== null ? (
                <AnswerPreview>
                    <p className="font-medium">
                        {documentRequest.answer_boolean ? t('Yes') : t('No')}
                    </p>
                </AnswerPreview>
            ) : null}
            {staffFallbackAllowed ? (
                <DocumentRequestAnswer
                    dossierId={dossierId}
                    documentRequest={documentRequest}
                />
            ) : null}
        </>
    );
}

/**
 * Single-choice answers show the selected option label.
 */
export function StaffChoiceRequestContent({
    dossierId,
    documentRequest,
}: StaffDocumentRequestTypeProps) {
    return (
        <StaffOptionAnswerContent
            dossierId={dossierId}
            documentRequest={documentRequest}
        />
    );
}

/**
 * Multi-select answers show every selected option label.
 */
export function StaffMultiSelectRequestContent({
    dossierId,
    documentRequest,
}: StaffDocumentRequestTypeProps) {
    return (
        <StaffOptionAnswerContent
            dossierId={dossierId}
            documentRequest={documentRequest}
        />
    );
}

function StaffOptionAnswerContent({
    dossierId,
    documentRequest,
}: StaffDocumentRequestTypeProps) {
    const staffFallbackAllowed = useStaffFallbackAllowed(documentRequest);
    const labels = (documentRequest.configuration?.options ?? [])
        .filter((option) =>
            documentRequest.answer_option_ids?.includes(option.id),
        )
        .map((option) => option.label);

    return (
        <>
            {labels.length > 0 ? (
                <AnswerPreview>
                    <p className="font-medium whitespace-pre-wrap">
                        {labels.join(', ')}
                    </p>
                </AnswerPreview>
            ) : null}
            {staffFallbackAllowed ? (
                <DocumentRequestAnswer
                    dossierId={dossierId}
                    documentRequest={documentRequest}
                />
            ) : null}
        </>
    );
}

function AnswerPreview({ children }: { children: ReactNode }) {
    const { t } = useTranslation();

    return (
        <div className="bg-muted/50 rounded-md px-3 py-2 text-sm">
            <p className="text-muted-foreground">{t('Answer')}</p>
            {children}
        </div>
    );
}
