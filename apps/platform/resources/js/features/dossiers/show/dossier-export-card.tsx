import { Form } from '@inertiajs/react';
import { Archive, Download, LoaderCircle } from 'lucide-react';
import DossierExportController from '@/actions/App/Http/Controllers/Dossiers/DossierExportController';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { Dossier } from '@/features/dossiers/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime } from '@/lib/format-date-time';

export default function DossierExportCard({ dossier }: { dossier: Dossier }) {
    const currentWorkspace = useCurrentWorkspace();
    const { t, locale } = useTranslation();

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

                {dossier.exports.length > 0 ? (
                    <div className="divide-y rounded-md border">
                        {dossier.exports.map((exportItem) => (
                            <div
                                key={exportItem.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm"
                            >
                                <div>
                                    <p className="font-medium">
                                        {t(
                                            exportItem.status === 'completed'
                                                ? 'Ready'
                                                : exportItem.status === 'failed'
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
                                    <Button asChild variant="outline" size="sm">
                                        <a href={exportItem.download_url}>
                                            <Download aria-hidden="true" />
                                            {t('Download')}
                                        </a>
                                    </Button>
                                ) : null}
                            </div>
                        ))}
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}
