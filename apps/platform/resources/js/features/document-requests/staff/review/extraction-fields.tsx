import { Eye, EyeOff } from 'lucide-react';
import { useMemo, useState } from 'react';
import EmptyState from '@/components/primitives/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    ConfidenceBadge,
    formatFieldValue,
    maskFieldValue,
    sensitivityLabel,
} from '@/features/document-requests/staff/review/document-extraction-helpers';
import { keyedFieldsList } from '@/features/document-requests/staff/review/document-extraction-keys';
import type { DocumentExtractionField } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Form fields detected by OCR, with optional masking for sensitive values.
 *
 * Laid out with container queries rather than viewport breakpoints: this same
 * component renders full width and inside the half-width preview column, so it
 * has to respond to its own box instead of the window.
 */
export default function ExtractionFields({
    fields,
}: {
    fields: DocumentExtractionField[];
}) {
    const { t } = useTranslation();
    const [revealedFields, setRevealedFields] = useState<
        Record<string, boolean>
    >({});
    const [query, setQuery] = useState('');

    const keyedFields = useMemo(() => keyedFieldsList(fields), [fields]);

    const trimmedQuery = query.trim().toLowerCase();
    const visibleFields = useMemo(() => {
        if (trimmedQuery === '') {
            return keyedFields;
        }

        // Matching a masked value never reveals it — only filters the list.
        return keyedFields.filter(
            ({ field }) =>
                field.label.toLowerCase().includes(trimmedQuery) ||
                formatFieldValue(field.value)
                    .toLowerCase()
                    .includes(trimmedQuery),
        );
    }, [keyedFields, trimmedQuery]);

    if (keyedFields.length === 0) {
        return <EmptyState title={t('No form fields detected.')} />;
    }

    return (
        <section className="space-y-3 pt-2">
            <Input
                type="search"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder={t('Search fields')}
                aria-label={t('Search fields')}
                className="max-w-xs"
            />

            {visibleFields.length === 0 ? (
                <EmptyState title={t('No fields match “:query”.', { query })} />
            ) : (
                <div className="@container">
                    <dl className="grid gap-x-6 divide-y rounded-md border @md:grid-cols-2 @md:divide-y-0 @4xl:grid-cols-3">
                        {visibleFields.map(({ field, key }) => {
                            const isSensitive = field.sensitivity !== null;
                            const isRevealed = revealedFields[key] === true;
                            const showValue = !isSensitive || isRevealed;

                            return (
                                <div
                                    key={key}
                                    className="min-w-0 px-3 py-2 @md:border-b"
                                >
                                    <dt className="text-muted-foreground flex flex-wrap items-center gap-2 text-xs font-medium">
                                        <span>{field.label}</span>
                                        {isSensitive && (
                                            <Badge variant="outline">
                                                {sensitivityLabel(
                                                    field.sensitivity,
                                                    t,
                                                )}
                                            </Badge>
                                        )}
                                    </dt>
                                    <dd className="mt-0.5 flex flex-wrap items-start gap-2 text-sm">
                                        <span className="min-w-0 flex-1 whitespace-pre-wrap">
                                            {showValue
                                                ? formatFieldValue(field.value)
                                                : maskFieldValue(field.value)}
                                        </span>
                                        <span className="flex shrink-0 items-center gap-1">
                                            {field.confidence !== null && (
                                                <ConfidenceBadge
                                                    confidence={
                                                        field.confidence
                                                    }
                                                />
                                            )}
                                            {isSensitive && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-7 px-2"
                                                    onClick={() =>
                                                        setRevealedFields(
                                                            (current) => ({
                                                                ...current,
                                                                [key]: !isRevealed,
                                                            }),
                                                        )
                                                    }
                                                    aria-label={
                                                        isRevealed
                                                            ? t(
                                                                  'Hide sensitive value',
                                                              )
                                                            : t(
                                                                  'Reveal sensitive value',
                                                              )
                                                    }
                                                >
                                                    {isRevealed ? (
                                                        <EyeOff className="size-3.5" />
                                                    ) : (
                                                        <Eye className="size-3.5" />
                                                    )}
                                                </Button>
                                            )}
                                        </span>
                                    </dd>
                                </div>
                            );
                        })}
                    </dl>
                </div>
            )}
        </section>
    );
}
