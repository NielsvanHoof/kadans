import type { PortalDocumentRequest } from '@/features/document-requests/types';
import { storesAnswerText } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Read-only rendering of a stored answer.
 *
 * Used for a locked this-period submission and for last period's suggestion.
 */
export function PortalAnswerPreview({
    documentRequest,
    label,
}: {
    documentRequest: PortalDocumentRequest;
    label: string;
}) {
    const { t } = useTranslation();
    const value = portalAnswerLabel(documentRequest, t);

    if (value === null) {
        return null;
    }

    return (
        <div className="bg-muted/50 rounded-lg px-3 py-2.5 text-sm">
            <p className="text-muted-foreground text-xs">{label}</p>
            <p className="mt-0.5 font-medium whitespace-pre-wrap">{value}</p>
        </div>
    );
}

export function portalAnswerLabel(
    documentRequest: PortalDocumentRequest,
    t: (key: string) => string,
): string | null {
    if (storesAnswerText(documentRequest.type)) {
        return documentRequest.answer_text || null;
    }

    if (
        documentRequest.type === 'boolean' &&
        documentRequest.answer_boolean !== null
    ) {
        return documentRequest.answer_boolean ? t('Yes') : t('No');
    }

    if (
        (documentRequest.type === 'choice' ||
            documentRequest.type === 'multi_select') &&
        documentRequest.answer_option_ids?.length
    ) {
        const labels = (documentRequest.configuration?.options ?? [])
            .filter((option) =>
                documentRequest.answer_option_ids?.includes(option.id),
            )
            .map((option) => option.label);

        return labels.length === 0 ? null : labels.join(', ');
    }

    return null;
}
