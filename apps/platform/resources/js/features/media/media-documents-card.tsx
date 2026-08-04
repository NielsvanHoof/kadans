import { FolderOpen } from 'lucide-react';
import UploadedDocumentController from '@/actions/App/Dossiers/Http/Controllers/UploadedDocumentController';
import EmptyState from '@/components/primitives/empty-state';
import { Badge } from '@/components/ui/badge';
import { buttonVariants, LinkButton } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { MediaDocument } from '@/features/media/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { fileIconForFilename } from '@/lib/file-icon-for-filename';
import { formatBytes } from '@/lib/format-bytes';
import { formatDateTime } from '@/lib/format-date-time';
import { show as showDossier } from '@/routes/workspaces/dossiers';
import type { Paginated } from '@/types/pagination';

/**
 * Media library list of document requests across all dossiers.
 */
export default function MediaDocumentsCard({
    documents,
    canDownload,
}: {
    documents: Paginated<MediaDocument>;
    canDownload: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t, locale } = useTranslation();
    const rows = documents.data;
    const total = documents.total;

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('All documents')}</CardTitle>
                <CardDescription>
                    {total === 1
                        ? t('1 document request')
                        : t(':count document requests', {
                              count: total,
                          })}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <EmptyState
                        title={t(
                            'Document requests will appear here once you add them to a dossier.',
                        )}
                    />
                ) : (
                    <ul className="divide-y rounded-md border">
                        {rows.map((document) => {
                            const firstUpload = document.uploaded_documents[0];
                            const extraUploadCount =
                                document.uploaded_documents.length - 1;
                            const filename =
                                firstUpload?.original_filename ?? '';
                            const FileIcon = filename
                                ? fileIconForFilename(filename)
                                : null;

                            return (
                                <li
                                    key={document.id}
                                    className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                                >
                                    <div className="flex min-w-0 flex-1 items-start gap-3">
                                        {FileIcon ? (
                                            <FileIcon
                                                className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                                aria-hidden
                                            />
                                        ) : null}
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium">
                                                {document.title}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {document.client_name}
                                                {' · '}
                                                {document.dossier.title}
                                                {document.dossier.reference &&
                                                    ` · ${document.dossier.reference}`}
                                            </p>
                                            {firstUpload ? (
                                                <p className="mt-1 text-sm text-muted-foreground">
                                                    {
                                                        firstUpload.original_filename
                                                    }
                                                    {' · '}
                                                    {formatBytes(
                                                        firstUpload.size_bytes,
                                                    )}
                                                    {' · '}
                                                    {formatDateTime(
                                                        firstUpload.uploaded_at,
                                                        locale,
                                                    )}
                                                    {extraUploadCount > 0 &&
                                                        ` · ${t('+:count more', { count: extraUploadCount })}`}
                                                </p>
                                            ) : (
                                                <p className="mt-1 text-sm text-muted-foreground">
                                                    {t('No file uploaded yet')}
                                                </p>
                                            )}
                                        </div>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant="secondary">
                                            {statusLabel(document.status, t)}
                                        </Badge>

                                        <LinkButton
                                            variant="outline"
                                            size="sm"
                                            href={
                                                showDossier({
                                                    tenant: currentWorkspace,
                                                    dossier:
                                                        document.dossier.id,
                                                }).url
                                            }
                                        >
                                            <FolderOpen />
                                            {t('Dossier')}
                                        </LinkButton>

                                        {firstUpload && canDownload && (
                                            <a
                                                className={buttonVariants({
                                                    variant: 'outline',
                                                    size: 'sm',
                                                })}
                                                href={UploadedDocumentController.download.url(
                                                    {
                                                        tenant: currentWorkspace,
                                                        uploadedDocument:
                                                            firstUpload.id,
                                                    },
                                                )}
                                            >
                                                {t('Download')}
                                            </a>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function statusLabel(
    status: string,
    t: (key: string, replacements?: Record<string, string | number>) => string,
): string {
    const labels: Record<string, string> = {
        pending: t('Pending'),
        submitted: t('Submitted'),
        accepted: t('Accepted'),
        rejected: t('Rejected'),
        changes_requested: t('Changes requested'),
    };

    return labels[status] ?? status;
}
