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

export type DocumentRequestStatus =
    'pending' | 'submitted' | 'accepted' | 'rejected';

/** Parallel OCR lifecycle on the uploaded file (independent of review status). */
export type DocumentProcessingStatus =
    'pending' | 'processing' | 'completed' | 'failed';

/** Uploaded file on a staff dossier document request (list payload — no OCR body). */
export type UploadedDocument = {
    id: number;
    original_filename: string;
    size_bytes: number;
    uploaded_at: string;
    processing_status: DocumentProcessingStatus;
    processing_error: string | null;
    pipeline: Pipeline | null;
};

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

/** Document request as shown on the staff dossier page. */
export type DocumentRequest = {
    id: number;
    type: QuestionnaireItemType;
    field_key: string;
    title: string;
    instructions: string | null;
    configuration: QuestionnaireItemConfiguration | null;
    status: DocumentRequestStatus;
    answer_text: string | null;
    answer_boolean: boolean | null;
    answer_option_ids: string[] | null;
    answered_at: string | null;
    reviewed_at: string | null;
    reviewed_by_name: string | null;
    rejection_reason: string | null;
    sort_order: number;
    /** False when a visibility rule currently hides this field from the client. */
    is_visible: boolean;
    /** False when the field does not count toward progress, review, or completion. */
    is_applicable: boolean;
    uploaded_document: UploadedDocument | null;
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
export type PortalUploadedDocument = {
    original_filename: string;
    size_bytes: number;
    uploaded_at: string | null;
};

/** Document request as shown on the client portal. */
export type PortalDocumentRequest = {
    id: number;
    type: QuestionnaireItemType;
    field_key: string;
    title: string;
    instructions: string | null;
    configuration: QuestionnaireItemConfiguration | null;
    status: DocumentRequestStatus;
    answer_text: string | null;
    answer_boolean: boolean | null;
    answer_option_ids: string[] | null;
    rejection_reason: string | null;
    can_respond: boolean;
    uploaded_document: PortalUploadedDocument | null;
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
