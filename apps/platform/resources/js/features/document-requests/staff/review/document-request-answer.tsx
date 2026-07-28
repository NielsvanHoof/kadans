import { Form } from '@inertiajs/react';
import { useState } from 'react';
import DocumentRequestReviewController from '@/actions/App/Dossiers/Http/Controllers/DocumentRequestReviewController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Field,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { AnswerTextControl } from '@/features/document-requests/controls/answer-text-control';
import type { DocumentRequest } from '@/features/document-requests/types';
import { storesAnswerText } from '@/features/document-requests/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { inlineActionOptions } from '@/lib/inline-action-options';

/**
 * Optional staff fallback for answers received by email, phone, or in person.
 * The normal client portal remains the primary response path.
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

    return (
        <Collapsible open={open} onOpenChange={setOpen} className="mt-2">
            <CollapsibleTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="px-0"
                >
                    {open
                        ? t('Hide staff answer')
                        : t('Answer on behalf of client')}
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent className="pt-2">
                {storesAnswerText(documentRequest.type) ? (
                    <StaffTextAnswerForm
                        dossierId={dossierId}
                        documentRequest={documentRequest}
                    />
                ) : (
                    <StaffBooleanAnswerForm
                        dossierId={dossierId}
                        documentRequest={documentRequest}
                    />
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}

function StaffTextAnswerForm({
    dossierId,
    documentRequest,
}: {
    dossierId: number;
    documentRequest: DocumentRequest;
}) {
    const { t } = useTranslation();
    const fieldId = `staff-answer-${documentRequest.id}`;

    return (
        <Form
            {...DocumentRequestReviewController.answer.form(
                useAnswerRouteParameters(dossierId, documentRequest.id),
            )}
            options={inlineActionOptions}
        >
            {({ errors, processing }) => (
                <FieldGroup className="gap-2">
                    <Field data-invalid={Boolean(errors.answer_text)}>
                        <FieldLabel htmlFor={fieldId}>
                            {t('Client answer')}
                        </FieldLabel>
                        <AnswerTextControl
                            id={fieldId}
                            type={documentRequest.type}
                            defaultValue={documentRequest.answer_text ?? ''}
                            aria-invalid={Boolean(errors.answer_text)}
                        />
                        <InputError message={errors.answer_text} />
                    </Field>
                    <Field orientation="horizontal">
                        <Button type="submit" size="sm" disabled={processing}>
                            {processing
                                ? t('Saving…')
                                : t('Save client answer')}
                        </Button>
                    </Field>
                </FieldGroup>
            )}
        </Form>
    );
}

function StaffBooleanAnswerForm({
    dossierId,
    documentRequest,
}: {
    dossierId: number;
    documentRequest: DocumentRequest;
}) {
    const { t } = useTranslation();
    const routeParameters = useAnswerRouteParameters(
        dossierId,
        documentRequest.id,
    );

    return (
        <FieldSet className="gap-2">
            <FieldLegend variant="label" className="mb-0">
                {t('Client answer')}
            </FieldLegend>
            <div className="flex flex-wrap gap-2">
                {[true, false].map((value) => (
                    <Form
                        key={String(value)}
                        {...DocumentRequestReviewController.answer.form(
                            routeParameters,
                        )}
                        options={inlineActionOptions}
                    >
                        {({ processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="answer_boolean"
                                    value={value ? '1' : '0'}
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                    variant={
                                        documentRequest.answer_boolean === value
                                            ? 'default'
                                            : 'outline'
                                    }
                                >
                                    {value ? t('Yes') : t('No')}
                                </Button>
                            </>
                        )}
                    </Form>
                ))}
            </div>
        </FieldSet>
    );
}

function useAnswerRouteParameters(
    dossierId: number,
    documentRequestId: number,
) {
    const currentWorkspace = useCurrentWorkspace();

    return {
        tenant: currentWorkspace,
        dossier: dossierId,
        documentRequest: documentRequestId,
    };
}
