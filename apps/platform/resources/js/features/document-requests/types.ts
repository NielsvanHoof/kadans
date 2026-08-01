import {
    type QuestionnaireItemType,
    questionnaireItemTypeCapabilities,
} from '@/features/document-requests/item-types/generated';
import type { Pipeline } from '@/features/dossiers/types';

export type { QuestionnaireItemType } from '@/features/document-requests/item-types/generated';
export {
    BOOLEAN_NO_OPTION_ID,
    BOOLEAN_YES_OPTION_ID,
    QUESTIONNAIRE_ITEM_TYPES,
    questionnaireItemTypeCapabilities,
} from '@/features/document-requests/item-types/generated';

export type QuestionnaireOption = { id: string; label: string };

export type QuestionnaireItemConfiguration = {
    is_required: boolean;
    options?: QuestionnaireOption[];
    visibility?: {
        source_field_key: string;
        option_ids: string[];
    };
};

/** Types whose answers are stored in answer_text (from generated PHP capabilities). */
export function storesAnswerText(type: QuestionnaireItemType): boolean {
    return questionnaireItemTypeCapabilities[type].storesAnswerText;
}

/** Types whose answers are stored as selected option ids. */
export function storesAnswerOptionIds(type: QuestionnaireItemType): boolean {
    return questionnaireItemTypeCapabilities[type].storesAnswerOptionIds;
}

/**
 * Types whose answer can drive another field's visibility rule
 * (from generated PHP capabilities).
 */
export function canDriveVisibility(type: QuestionnaireItemType): boolean {
    return questionnaireItemTypeCapabilities[type].canDriveVisibility;
}

export type DocumentRequestStatus = App.Dossiers.Enums.DocumentRequestStatus;

/** Parallel OCR lifecycle on the uploaded file (independent of review status). */
export type DocumentProcessingStatus =
    App.DocumentProcessing.Enums.DocumentProcessingStatus;

/** Uploaded file on a staff dossier document request (list payload — no OCR body). */
export type UploadedDocument = App.Dossiers.Data.StaffUploadedDocumentData;

/** Parsed Bedrock-structured OCR payload on the extraction page. */
export type ExtractionFieldSensitivity =
    | 'bsn'
    | 'iban'
    | 'id_number'
    | 'account_number'
    | 'date_of_birth'
    | 'email'
    | 'phone';

export type DocumentExtractionField = {
    label: string;
    value: string | string[];
    /** Textract LINE match score 0–1, or null when unmatched / legacy. */
    confidence: number | null;
    /** PII category for UI masking, or null when not sensitive. */
    sensitivity: ExtractionFieldSensitivity | null;
};

export type DocumentExtractionTable = {
    title: string | null;
    headers: string[];
    rows: string[][];
};

export type DocumentExtraction = {
    document_type: string | null;
    summary: string | null;
    fields: DocumentExtractionField[];
    tables: DocumentExtractionTable[];
    notes: string[];
    /** Average Textract LINE confidence 0–1, or null when unknown. */
    confidence: number | null;
};

/**
 * Document request as shown on the staff dossier page.
 *
 * The generated Data shape is the source of truth for every field; only
 * `configuration` is refined, because PHP stores it as a free-form JSON column
 * (`array<string, mixed>`) while the builder needs its structure.
 */
export type DocumentRequest = Omit<
    App.Dossiers.Data.StaffDocumentRequestData,
    'configuration'
> & {
    configuration: QuestionnaireItemConfiguration | null;
};

/**
 * Whether `field` sits before `other` on the canvas.
 * Mirrors DocumentRequest::isOrderedBefore() so the builder offers exactly the
 * visibility sources the backend will accept.
 */
export function isOrderedBefore(
    field: Pick<DocumentRequest, 'id' | 'sort_order'>,
    other: Pick<DocumentRequest, 'id' | 'sort_order'>,
): boolean {
    if (field.sort_order !== other.sort_order) {
        return field.sort_order < other.sort_order;
    }

    return field.id < other.id;
}

/** Uploaded file as shown on the client portal (no download id). */
export type PortalUploadedDocument =
    App.ClientPortal.Data.PortalUploadedDocumentData;

/** Document request as shown on the client portal. See DocumentRequest re configuration. */
export type PortalDocumentRequest = Omit<
    App.ClientPortal.Data.PortalDocumentRequestData,
    'configuration'
> & {
    configuration: QuestionnaireItemConfiguration | null;
};

/** Dedicated OCR extraction page props (staff). */
export type UploadedDocumentShowProps = {
    uploaded_document: UploadedDocument & {
        extraction: DocumentExtraction | null;
        raw_json: string | null;
    };
    document_request: { id: number; title: string } | null;
    dossier: { id: number; title: string } | null;
    can_download: boolean;
};
