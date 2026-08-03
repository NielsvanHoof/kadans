import EmptyState from '@/components/primitives/empty-state';
import ExtractionFields from '@/features/document-requests/staff/review/extraction-fields';
import ExtractionOverview from '@/features/document-requests/staff/review/extraction-overview';
import ExtractionRawJson from '@/features/document-requests/staff/review/extraction-raw-json';
import ExtractionTables from '@/features/document-requests/staff/review/extraction-tables';
import type { DocumentExtraction } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Renders Bedrock-structured OCR fields, tables, and notes, with raw JSON collapsed.
 * Sensitive fields start masked; confidence badges use Textract LINE match scores.
 */
export default function DocumentExtractionView({
    extraction,
    rawJson,
}: {
    extraction: DocumentExtraction | null;
    rawJson: string | null;
}) {
    const { t } = useTranslation();

    if (extraction === null && (rawJson === null || rawJson === '')) {
        return (
            <EmptyState
                title={t('No extraction data is available for this file yet.')}
            />
        );
    }

    const fields = extraction?.fields ?? [];
    const tables = extraction?.tables ?? [];
    const notes = extraction?.notes ?? [];

    return (
        <div className="space-y-8">
            <ExtractionOverview
                documentType={extraction?.document_type ?? null}
                summary={extraction?.summary ?? null}
                documentConfidence={extraction?.confidence ?? null}
            />
            <ExtractionFields fields={fields} />
            <ExtractionTables tables={tables} />
            <ExtractionRawJson notes={notes} rawJson={rawJson} />
        </div>
    );
}
