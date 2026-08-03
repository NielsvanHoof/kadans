import { Form } from '@inertiajs/react';
import DocumentRequestReviewController from '@/actions/App/Dossiers/Http/Controllers/DocumentRequestReviewController';
import InputError from '@/components/primitives/input-error';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { AnswerTextControl } from '@/features/document-requests/controls/answer-text-control';
import { useAnswerRouteParameters } from '@/features/document-requests/staff/review/use-answer-route-parameters';
import type { DocumentRequest } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';
import { inlineActionOptions } from '@/lib/inline-action-options';

/** Every staff answer form takes the same props so the registry can swap them. */
export type StaffAnswerFormProps = {
    dossierId: number;
    documentRequest: DocumentRequest;
};

/** Free text, date, and number answers all post a single answer_text value. */
export function StaffTextAnswerForm({
    dossierId,
    documentRequest,
}: StaffAnswerFormProps) {
    const { t } = useTranslation();
    const routeParameters = useAnswerRouteParameters(
        dossierId,
        documentRequest.id,
    );
    const fieldId = `staff-answer-${documentRequest.id}`;

    return (
        <Form
            {...DocumentRequestReviewController.answer.form(routeParameters)}
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
                        <Button type="submit" size="sm" isDisabled={processing}>
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

/** Yes/no answers save on click, matching the portal's one-tap behaviour. */
export function StaffBooleanAnswerForm({
    dossierId,
    documentRequest,
}: StaffAnswerFormProps) {
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
                                    isDisabled={processing}
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
