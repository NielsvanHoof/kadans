import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { getStaffAnswerForm } from '@/features/document-requests/questionnaire-item-type-registry';
import type { DocumentRequest } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Optional staff fallback for answers received by email, phone, or in person.
 * The normal client portal remains the primary response path.
 *
 * The form itself comes from the registry, so this shell never branches on type.
 */
export default function DocumentRequestAnswer({
    dossierId,
    documentRequest,
}: {
    dossierId: number;
    documentRequest: DocumentRequest;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const AnswerForm = getStaffAnswerForm(documentRequest.type);

    // File requests are answered by uploading, handled elsewhere on the card.
    if (AnswerForm === null) {
        return null;
    }

    return (
        <Collapsible
            isExpanded={open}
            onExpandedChange={setOpen}
            className="mt-2"
        >
            <CollapsibleTrigger
                type="button"
                variant="ghost"
                size="sm"
                className="px-0"
            >
                {open
                    ? t('Hide staff answer')
                    : t('Answer on behalf of client')}
            </CollapsibleTrigger>
            <CollapsibleContent className="pt-2">
                <AnswerForm
                    dossierId={dossierId}
                    documentRequest={documentRequest}
                />
            </CollapsibleContent>
        </Collapsible>
    );
}
