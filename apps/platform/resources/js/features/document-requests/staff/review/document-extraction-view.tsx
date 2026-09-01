import { useState } from 'react';
import EmptyState from '@/components/primitives/empty-state';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import ExtractionFields from '@/features/document-requests/staff/review/extraction-fields';
import ExtractionOverview from '@/features/document-requests/staff/review/extraction-overview';
import ExtractionRawJson from '@/features/document-requests/staff/review/extraction-raw-json';
import ExtractionTables from '@/features/document-requests/staff/review/extraction-tables';
import type { DocumentExtraction } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

type ExtractionTab = 'overview' | 'fields' | 'tables' | 'raw';

/**
 * Tabbed view of an OCR extraction: Textract fields and tables, plus the
 * document type / summary / notes written by DocumentOverviewAgent.
 *
 * Sensitive fields start masked; confidence badges use Textract match scores.
 *
 * All four tabs always render, even when empty. The page live-refreshes while
 * OCR runs (see useFetchOnPipelineUpdated), so a tab set that grew as results
 * arrived would shift the UI under the reader mid-review.
 */
export default function DocumentExtractionView({
    extraction,
    rawJson,
}: {
    extraction: DocumentExtraction | null;
    rawJson: string | null;
}) {
    const { t } = useTranslation();
    const [tab, setTab] = useState<ExtractionTab>('overview');

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
        <Tabs
            selectedKey={tab}
            onSelectionChange={(key) => setTab(String(key) as ExtractionTab)}
        >
            {/* TabsList is `inline-flex w-fit` with `whitespace-nowrap`
                triggers, so it can neither wrap nor shrink — four labels with
                counts overflow a phone. Same ScrollArea treatment as
                dossier-panels.tsx. */}
            <ScrollArea className="w-full">
                <TabsList variant="line">
                    <TabsTrigger id="overview">{t('Overview')}</TabsTrigger>
                    <TabsTrigger id="fields">
                        {t(':count fields', { count: fields.length })}
                    </TabsTrigger>
                    <TabsTrigger id="tables">
                        {t(':count tables', { count: tables.length })}
                    </TabsTrigger>
                    <TabsTrigger id="raw">{t('Full data')}</TabsTrigger>
                </TabsList>
            </ScrollArea>

            <TabsContent id="overview">
                <ExtractionOverview
                    documentType={extraction?.document_type ?? null}
                    summary={extraction?.summary ?? null}
                    documentConfidence={extraction?.confidence ?? null}
                    notes={notes}
                />
            </TabsContent>

            <TabsContent id="fields">
                <ExtractionFields fields={fields} />
            </TabsContent>

            <TabsContent id="tables">
                <ExtractionTables tables={tables} />
            </TabsContent>

            <TabsContent id="raw">
                <ExtractionRawJson rawJson={rawJson} />
            </TabsContent>
        </Tabs>
    );
}
