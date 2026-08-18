import { useMemo } from 'react';
import EmptyState from '@/components/primitives/empty-state';
import { ConfidenceBadge } from '@/features/document-requests/staff/review/document-extraction-helpers';
import { keyedStrings } from '@/features/document-requests/staff/review/document-extraction-keys';
import type { DocumentExtraction } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Document type, OCR confidence, summary, and extraction notes.
 *
 * No heading of its own — the enclosing tab already labels this panel.
 */
export default function ExtractionOverview({
    documentType,
    summary,
    documentConfidence,
    notes,
}: {
    documentType: DocumentExtraction['document_type'];
    summary: DocumentExtraction['summary'];
    documentConfidence: DocumentExtraction['confidence'];
    notes: string[];
}) {
    const { t } = useTranslation();
    const keyedNotes = useMemo(() => keyedStrings(notes), [notes]);

    if (
        documentType === null &&
        summary === null &&
        documentConfidence === null &&
        keyedNotes.length === 0
    ) {
        return <EmptyState title={t('No overview was generated.')} />;
    }

    return (
        <section className="space-y-3 pt-2">
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
            {keyedNotes.length > 0 && (
                <div className="space-y-1">
                    <h3 className="text-sm font-medium">{t('Notes')}</h3>
                    <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                        {keyedNotes.map(({ value: note, key }) => (
                            <li key={`note:${key}`}>{note}</li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
