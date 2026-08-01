import { Form } from '@inertiajs/react';
import { Archive, Download, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import DossierExportController from '@/actions/App/Dossiers/Http/Controllers/DossierExportController';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { Dossier, DossierExport } from '@/features/dossiers/types';
import { useFetchOnPipelineUpdated } from '@/features/pipelines/hooks/use-fetch-on-pipeline-updated';
import {
    pipelineStepLabel,
    pipelineStepStatusLabel,
} from '@/features/pipelines/status/pipeline-status';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime } from '@/lib/format-date-time';

/**
 * Dossier ZIP export card. In-flight exports refresh via a JSON island.
 */
export default function DossierExportCard({ dossier }: { dossier: Dossier }) {
    const currentWorkspace = useCurrentWorkspace();
    const { t, locale } = useTranslation();
    const [exports, setExports] = useState(dossier.exports);

    // Sync when Inertia replaces the dossier prop (create export redirect).
    useEffect(() => {
        setExports(dossier.exports);
    }, [dossier.exports]);

    const hasInFlightProcessing = exports.some((exportItem) => {
        return (
            exportItem.status === 'pending' ||
            exportItem.status === 'processing'
        );
    });

    useFetchOnPipelineUpdated<{ export: DossierExport }>({
        enabled: hasInFlightProcessing,
        match: (event) =>
            event.dossier_id === dossier.id &&
            event.type === 'dossier_export' &&
            event.subject_type === 'dossier_export',
        urlForEvent: (event) =>
            DossierExportController.show.url({
                tenant: currentWorkspace,
                dossier: dossier.id,
                export: event.subject_id,
            }),
        onData: ({ export: nextExport }) => {
            setExports((previous) =>
                previous.map((item) =>
                    item.id === nextExport.id ? nextExport : item,
                ),
            );
        },
    });

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Archive aria-hidden="true" />
                    {t('Export dossier')}
                </CardTitle>
                <CardDescription>
                    {t(
                        'Create a secure ZIP archive containing the dossier answers and uploaded documents.',
                    )}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <Form
                    {...DossierExportController.store.form({
                        tenant: currentWorkspace,
                        dossier: dossier.id,
                    })}
                >
                    {({ processing }) => (
                        <Button type="submit" disabled={processing}>
                            {processing ? (
                                <LoaderCircle
                                    className="animate-spin"
                                    aria-hidden="true"
                                />
                            ) : (
                                <Archive aria-hidden="true" />
                            )}
                            {t('Create export')}
                        </Button>
                    )}
                </Form>

                {exports.length > 0 ? (
                    <div className="divide-y rounded-md border">
                        {exports.map((exportItem) => (
                            <div
                                key={exportItem.id}
                                className="px-4 py-3 text-sm"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p className="font-medium">
                                            {t(
                                                exportItem.status ===
                                                    'completed'
                                                    ? 'Ready'
                                                    : exportItem.status ===
                                                        'failed'
                                                      ? 'Failed'
                                                      : 'Preparing',
                                            )}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {t('Requested :datetime', {
                                                datetime: formatDateTime(
                                                    exportItem.created_at,
                                                    locale,
                                                ),
                                            })}
                                            {exportItem.expires_at
                                                ? ` · ${t('Expires :datetime', {
                                                      datetime: formatDateTime(
                                                          exportItem.expires_at,
                                                          locale,
                                                      ),
                                                  })}`
                                                : null}
                                        </p>
                                        {exportItem.error ? (
                                            <p className="text-destructive">
                                                {exportItem.error}
                                            </p>
                                        ) : null}
                                    </div>
                                    {exportItem.download_url ? (
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <a href={exportItem.download_url}>
                                                <Download aria-hidden="true" />
                                                {t('Download')}
                                            </a>
                                        </Button>
                                    ) : null}
                                </div>
                                {exportItem.pipeline ? (
                                    <div className="mt-2 flex flex-wrap gap-2 text-xs text-muted-foreground">
                                        {exportItem.pipeline.steps.map(
                                            (step) => (
                                                <span
                                                    key={step.key}
                                                    className="rounded-full border px-2 py-1"
                                                >
                                                    {pipelineStepLabel(
                                                        step.key,
                                                        t,
                                                    )}
                                                    :{' '}
                                                    {pipelineStepStatusLabel(
                                                        step.status,
                                                        t,
                                                    )}
                                                </span>
                                            ),
                                        )}
                                    </div>
                                ) : null}
                            </div>
                        ))}
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}
