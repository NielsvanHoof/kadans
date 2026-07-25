import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import EmptyState from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
    const keyedFields = keyedFieldsList(fields);

    return (
        <section className="space-y-3">
            <h2 className="text-lg font-semibold tracking-tight">
                {t('Fields')}
            </h2>
            {keyedFields.length === 0 ? (
                <EmptyState title={t('No form fields detected.')} />
            ) : (
                <dl className="divide-y rounded-md border">
                    {keyedFields.map(({ field, key }) => {
                        const isSensitive = field.sensitivity !== null;
                        const isRevealed = revealedFields[key] === true;
                        const showValue = !isSensitive || isRevealed;

                        return (
                            <div
                                key={key}
                                className="grid gap-1 px-3 py-2 sm:grid-cols-[minmax(8rem,14rem)_1fr] sm:gap-4"
                            >
                                <dt className="flex flex-wrap items-center gap-2 text-sm font-medium text-muted-foreground">
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
                                <dd className="flex flex-wrap items-start gap-2 text-sm">
                                    <span className="min-w-0 flex-1 whitespace-pre-wrap">
                                        {showValue
                                            ? formatFieldValue(field.value)
                                            : maskFieldValue(field.value)}
                                    </span>
                                    <span className="flex shrink-0 items-center gap-1">
                                        {field.confidence !== null && (
                                            <ConfidenceBadge
                                                confidence={field.confidence}
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
            )}
        </section>
    );
}
