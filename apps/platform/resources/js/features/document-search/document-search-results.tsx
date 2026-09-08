import { Link } from '@inertiajs/react';
import EmptyState from '@/components/primitives/empty-state';
import { Spinner } from '@/components/ui/spinner';
import type { DocumentSearchHit } from '@/features/document-search/types';
import { useTranslation } from '@/hooks/use-translation';

export default function DocumentSearchResults({
    hits,
    truncated,
    processing,
    hasQuery,
    onNavigate,
}: {
    hits: DocumentSearchHit[];
    truncated: boolean;
    processing: boolean;
    hasQuery: boolean;
    onNavigate: () => void;
}) {
    const { t } = useTranslation();

    if (!hasQuery) {
        return (
            <EmptyState
                variant="compact"
                title={t('Search uploaded documents')}
                description={t(
                    'Find a file by name, identifier, or what it is about.',
                )}
            />
        );
    }

    if (processing && hits.length === 0) {
        return (
            <div className="flex justify-center py-6">
                <Spinner />
            </div>
        );
    }

    if (hits.length === 0) {
        return (
            <EmptyState
                variant="compact"
                title={t('No matching documents')}
                description={t('Try a filename, client, or a shorter phrase.')}
            />
        );
    }

    return (
        <div className="flex flex-col gap-2">
            {truncated ? (
                <p
                    role="status"
                    className="text-muted-foreground text-sm text-pretty"
                >
                    {t(
                        'Search results are limited to the first matches. Narrow your query to see more.',
                    )}
                </p>
            ) : null}
            <ul className="flex flex-col gap-1">
                {hits.map((hit) => (
                    <li key={hit.id}>
                        <Link
                            href={hit.show_url}
                            onClick={onNavigate}
                            className="hover:bg-muted/50 focus-visible:ring-ring block rounded-md px-2 py-1.5 focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <span className="block font-medium">
                                {hit.original_filename}
                            </span>
                            <span className="text-muted-foreground block">
                                {hit.client_name}
                                {' · '}
                                {hit.dossier_title}
                                {hit.document_type
                                    ? ` · ${hit.document_type}`
                                    : ''}
                            </span>
                            {hit.summary ? (
                                <span className="text-muted-foreground mt-0.5 line-clamp-2 block">
                                    {hit.summary}
                                </span>
                            ) : null}
                        </Link>
                    </li>
                ))}
            </ul>
        </div>
    );
}
