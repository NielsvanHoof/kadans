import { ConfidenceBadge } from '@/features/document-requests/staff/review/document-extraction-helpers';
import type { DocumentExtraction } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Document type, OCR confidence, and summary from extraction.
 */
export default function ExtractionOverview({
    documentType,
    summary,
    documentConfidence,
}: {
    documentType: DocumentExtraction['document_type'];
    summary: DocumentExtraction['summary'];
    documentConfidence: DocumentExtraction['confidence'];
}) {
    const { t } = useTranslation();

    if (
        documentType === null &&
        summary === null &&
        documentConfidence === null
    ) {
        return null;
    }

    return (
        <section className="space-y-2">
            <h2 className="text-lg font-semibold tracking-tight">
                {t('Overview')}
            </h2>
            {documentType !== null && (
                <p className="text-sm text-muted-foreground">
                    <span className="font-medium text-foreground">
                        {t('Type')}:
                    </span>{' '}
                    {documentType}
                </p>
            )}
            {documentConfidence !== null && (
                <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <span className="font-medium text-foreground">
                        {t('OCR confidence')}:
                    </span>
                    <ConfidenceBadge confidence={documentConfidence} />
                </p>
            )}
            {summary !== null && (
                <p className="text-sm whitespace-pre-wrap">{summary}</p>
            )}
        </section>
    );
}
