import { Form } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import ClientPortalAnswerController from '@/actions/App/ClientPortal/Http/Controllers/Submissions/ClientPortalAnswerController';
import { Button } from '@/components/ui/button';
import { PortalAnswerPreview } from '@/features/document-requests/portal/portal-answer-preview';
import type { PortalDocumentRequest } from '@/features/document-requests/types';
import {
    storesAnswerOptionIds,
    storesAnswerText,
} from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';
import { inlineActionOptions } from '@/lib/inline-action-options';

/**
 * Last period's answer is a suggestion, not this period's submission.
 * Confirm or change before it counts as complete.
 */
export function PortalCarriedForwardAnswer({
    documentRequest,
    children,
}: {
    documentRequest: PortalDocumentRequest;
    children: ReactNode;
}) {
    const { t } = useTranslation();
    const [isChanging, setIsChanging] = useState(false);

    return (
        <div className="space-y-4">
            <PortalAnswerPreview
                documentRequest={documentRequest}
                label={t('Your answer last year')}
            />
            {isChanging ? (
                children
            ) : (
                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <Form
                        {...ClientPortalAnswerController.store.form({
                            documentRequest: documentRequest.id,
                        })}
                        options={inlineActionOptions}
                    >
                        {({ processing }) => (
                            <>
                                <CarriedForwardConfirmFields
                                    documentRequest={documentRequest}
                                />
                                <Button
                                    type="submit"
                                    isDisabled={processing}
                                    className="w-full sm:w-auto"
                                >
                                    {processing
                                        ? t('Saving…')
                                        : t('Still correct')}
                                </Button>
                            </>
                        )}
                    </Form>
                    <Button
                        type="button"
                        variant="outline"
                        onPress={() => setIsChanging(true)}
                        className="w-full sm:w-auto"
                    >
                        {t('Change answer')}
                    </Button>
                </div>
            )}
        </div>
    );
}

function CarriedForwardConfirmFields({
    documentRequest,
}: {
    documentRequest: PortalDocumentRequest;
}) {
    if (storesAnswerText(documentRequest.type) && documentRequest.answer_text) {
        return (
            <input
                type="hidden"
                name="answer_text"
                value={documentRequest.answer_text}
            />
        );
    }

    if (
        documentRequest.type === 'boolean' &&
        documentRequest.answer_boolean !== null
    ) {
        return (
            <input
                type="hidden"
                name="answer_boolean"
                value={documentRequest.answer_boolean ? '1' : '0'}
            />
        );
    }

    if (
        storesAnswerOptionIds(documentRequest.type) &&
        documentRequest.answer_option_ids?.length
    ) {
        return (
            <>
                {documentRequest.answer_option_ids.map((optionId) => (
                    <input
                        key={optionId}
                        type="hidden"
                        name="answer_option_ids[]"
                        value={optionId}
                    />
                ))}
            </>
        );
    }

    return null;
}
