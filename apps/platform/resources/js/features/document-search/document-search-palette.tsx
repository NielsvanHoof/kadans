import { useHttp, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useDebounceCallback } from 'usehooks-ts';
import DocumentSearchController from '@/actions/App/Reporting/Http/Controllers/DocumentSearch/DocumentSearchController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import DocumentSearchAskPanel from '@/features/document-search/document-search-ask-panel';
import DocumentSearchResults from '@/features/document-search/document-search-results';
import type { DocumentSearchHit } from '@/features/document-search/types';
import { useAiFeatureEnabled } from '@/hooks/use-firm-settings';
import { useTranslation } from '@/hooks/use-translation';

const SEARCH_DEBOUNCE_MS = 250;

export default function DocumentSearchPalette() {
    const { t } = useTranslation();
    const { current_firm: currentWorkspace } = usePage().props;
    const askEnabled = useAiFeatureEnabled('document_search');
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [hits, setHits] = useState<DocumentSearchHit[]>([]);
    const [truncated, setTruncated] = useState(false);
    const { get, processing } = useHttp({});

    const runSearch = useDebounceCallback(async (term: string) => {
        if (term === '') {
            setHits([]);
            setTruncated(false);

            return;
        }

        try {
            if (!currentWorkspace) {
                return;
            }

            const response = (await get(
                DocumentSearchController.index.url(
                    { tenant: currentWorkspace },
                    { query: { q: term } },
                ),
            )) as
                | {
                      hits: DocumentSearchHit[];
                      truncated: boolean;
                  }
                | undefined;

            if (response === undefined) {
                return;
            }

            setHits(response.hits);
            setTruncated(response.truncated);
        } catch {
            setHits([]);
            setTruncated(false);
        }
    }, SEARCH_DEBOUNCE_MS);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent): void => {
            if (event.key !== 'k' || !(event.metaKey || event.ctrlKey)) {
                return;
            }

            event.preventDefault();
            setOpen((current) => !current);
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    if (!currentWorkspace) {
        return null;
    }

    function closePalette(): void {
        setOpen(false);
    }

    return (
        <DialogTrigger
            isOpen={open}
            onOpenChange={(nextOpen) => {
                setOpen(nextOpen);

                if (!nextOpen) {
                    setQuery('');
                    setHits([]);
                    setTruncated(false);
                    runSearch.cancel();
                }
            }}
        >
            <Button
                variant="ghost"
                size="icon"
                aria-label={t('Search documents')}
                aria-keyshortcuts="Meta+K Control+K"
            >
                <Search className="size-5" aria-hidden="true" />
            </Button>
            <Dialog className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('Search documents')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'Find a file by name, identifier, or what it is about.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <Tabs>
                    <TabsList aria-label={t('Search documents')}>
                        <TabsTrigger id="search">{t('Search')}</TabsTrigger>
                        {askEnabled ? (
                            <TabsTrigger id="ask">{t('Ask')}</TabsTrigger>
                        ) : null}
                    </TabsList>
                    <TabsContent id="search" className="flex flex-col gap-3">
                        <Input
                            type="search"
                            value={query}
                            autoFocus
                            maxLength={255}
                            placeholder={t('Search documents')}
                            aria-label={t('Search documents')}
                            onChange={(event) => {
                                const next = event.target.value;
                                setQuery(next);
                                void runSearch(next.trim());
                            }}
                        />
                        <DocumentSearchResults
                            hits={hits}
                            truncated={truncated}
                            processing={processing}
                            hasQuery={query.trim() !== ''}
                            onNavigate={closePalette}
                        />
                    </TabsContent>
                    {askEnabled ? (
                        <TabsContent id="ask">
                            <DocumentSearchAskPanel onNavigate={closePalette} />
                        </TabsContent>
                    ) : null}
                </Tabs>
            </Dialog>
        </DialogTrigger>
    );
}
