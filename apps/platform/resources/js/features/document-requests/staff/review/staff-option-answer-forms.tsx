import { Form } from '@inertiajs/react';
import DocumentRequestReviewController from '@/actions/App/Dossiers/Http/Controllers/DocumentRequestReviewController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldGroup,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import type { StaffAnswerFormProps } from '@/features/document-requests/staff/review/staff-answer-forms';
import { useAnswerRouteParameters } from '@/features/document-requests/staff/review/use-answer-route-parameters';
import { useTranslation } from '@/hooks/use-translation';
import { inlineActionOptions } from '@/lib/inline-action-options';

/**
 * Single choice saves the moment an option is picked, so each option is its own
 * one-field form. This mirrors the portal, where selecting also saves.
 */
export function StaffChoiceAnswerForm({
    dossierId,
    documentRequest,
}: StaffAnswerFormProps) {
    const { t } = useTranslation();
    const routeParameters = useAnswerRouteParameters(
        dossierId,
        documentRequest.id,
    );
    const options = documentRequest.configuration?.options ?? [];

    return (
        <FieldSet className="gap-2">
            <FieldLegend variant="label" className="mb-0">
                {t('Client answer')}
            </FieldLegend>
            <div className="flex flex-col gap-2">
                {options.map((option) => (
                    <Form
                        key={option.id}
                        {...DocumentRequestReviewController.answer.form(
                            routeParameters,
                        )}
                        options={inlineActionOptions}
                    >
                        {({ processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="answer_option_ids[]"
                                    value={option.id}
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                    variant={
                                        documentRequest
                                            .answer_option_ids?.[0] ===
                                        option.id
                                            ? 'default'
                                            : 'outline'
                                    }
                                    className="justify-start"
                                >
                                    {option.label}
                                </Button>
                            </>
                        )}
                    </Form>
                ))}
            </div>
        </FieldSet>
    );
}

/**
 * Multi-select needs an explicit submit: the client is not done choosing until
 * they say so, unlike the single-choice case above.
 */
export function StaffMultiSelectAnswerForm({
    dossierId,
    documentRequest,
}: StaffAnswerFormProps) {
    const { t } = useTranslation();
    const routeParameters = useAnswerRouteParameters(
        dossierId,
        documentRequest.id,
    );
    const options = documentRequest.configuration?.options ?? [];
    const selected = new Set(documentRequest.answer_option_ids ?? []);

    return (
        <Form
            {...DocumentRequestReviewController.answer.form(routeParameters)}
            options={inlineActionOptions}
        >
            {({ errors, processing }) => (
                <FieldGroup className="gap-2">
                    <FieldSet className="gap-2">
                        <FieldLegend variant="label" className="mb-0">
                            {t('Client answer')}
                        </FieldLegend>
                        <div className="space-y-2">
                            {options.map((option) => (
                                <label
                                    key={option.id}
                                    className="flex cursor-pointer items-center gap-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        name="answer_option_ids[]"
                                        value={option.id}
                                        defaultChecked={selected.has(option.id)}
                                        className="size-4 rounded-[4px] border border-input"
                                    />
                                    <span>{option.label}</span>
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.answer_option_ids} />
                    </FieldSet>
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
