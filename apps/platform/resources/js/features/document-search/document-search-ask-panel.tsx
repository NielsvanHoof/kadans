import { Link, useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import DocumentSearchController from '@/actions/App/Reporting/Http/Controllers/DocumentSearch/DocumentSearchController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { DocumentSearchAskResult } from '@/features/document-search/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';

export default function DocumentSearchAskPanel({
    onNavigate,
}: {
    onNavigate: () => void;
}) {
    const { t } = useTranslation();
    const currentWorkspace = useCurrentWorkspace();
    const [question, setQuestion] = useState('');
    const [result, setResult] = useState<DocumentSearchAskResult | null>(null);
    const { post, processing, setData } = useHttp<
        { question: string },
        DocumentSearchAskResult
    >({
        question: '',
    });

    async function ask() {
        const trimmed = question.trim();

        if (trimmed === '') {
            return;
        }

        setData('question', trimmed);

        try {
            const response = await post(
                DocumentSearchController.store.url({
                    tenant: currentWorkspace,
                }),
            );

            setResult(response);
        } catch {
            toast.error(t('Could not answer that question. Please try again.'));
        }
    }

    return (
        <div className="flex flex-col gap-3">
            <Textarea
                value={question}
                onChange={(event) => setQuestion(event.target.value)}
                maxLength={500}
                placeholder={t(
                    'Ask which documents mention salary, identity, or a client.',
                )}
                aria-label={t('Question about documents')}
            />
            <Button
                type="button"
                onClick={() => {
                    void ask();
                }}
                isDisabled={processing || question.trim() === ''}
            >
                {processing ? <Spinner /> : null}
                {t('Ask')}
            </Button>
            {result ? (
                <div className="flex flex-col gap-3">
                    <p className="text-pretty">{result.answer}</p>
                    {result.citations.length > 0 ? (
                        <ul className="flex flex-col gap-1">
                            {result.citations.map((citation) => (
                                <li key={citation.uploaded_document_id}>
                                    <Link
                                        href={citation.show_url}
                                        onClick={onNavigate}
                                        className="hover:bg-muted/50 focus-visible:ring-ring block rounded-md px-2 py-1.5 focus-visible:ring-2 focus-visible:outline-none"
                                    >
                                        <span className="block font-medium">
                                            {citation.original_filename}
                                        </span>
                                        {citation.why ? (
                                            <span className="text-muted-foreground block">
                                                {citation.why}
                                            </span>
                                        ) : null}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
