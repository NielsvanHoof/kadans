import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { formatRawJson } from '@/features/document-requests/staff/review/document-extraction-helpers';
import { keyedStrings } from '@/features/document-requests/staff/review/document-extraction-keys';
import { useTranslation } from '@/hooks/use-translation';

/**
 * OCR notes and collapsible raw JSON payload.
 */
export default function ExtractionRawJson({
    notes,
    rawJson,
}: {
    notes: string[];
    rawJson: string | null;
}) {
    const { t } = useTranslation();
    const keyedNotes = keyedStrings(notes);

    return (
        <>
            {keyedNotes.length > 0 && (
                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">
                        {t('Notes')}
                    </h2>
                    <ul className="list-disc space-y-1 pl-5 text-sm">
                        {keyedNotes.map(({ value: note, key }) => (
                            <li key={`note:${key}`}>{note}</li>
                        ))}
                    </ul>
                </section>
            )}

            {rawJson !== null && rawJson !== '' && (
                <Collapsible className="space-y-2">
                    <CollapsibleTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="px-0"
                        >
                            {t('Raw JSON')}
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <pre className="max-h-96 overflow-auto rounded-md border bg-muted/40 px-3 py-2 font-mono text-xs whitespace-pre-wrap">
                            {formatRawJson(rawJson)}
                        </pre>
                    </CollapsibleContent>
                </Collapsible>
            )}
        </>
    );
}
