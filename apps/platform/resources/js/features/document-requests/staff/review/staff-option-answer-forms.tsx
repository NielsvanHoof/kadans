import { Form } from '@inertiajs/react';
import DocumentRequestReviewController from '@/actions/App/Dossiers/Http/Controllers/DocumentRequests/DocumentRequestReviewController';
import InputError from '@/components/primitives/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import type { StaffAnswerFormProps } from '@/features/document-requests/staff/review/staff-answer-forms';
import { useAnswerRouteParameters } from '@/features/document-requests/staff/review/use-answer-route-parameters';
import { useTranslation } from '@/hooks/use-translation';
import { inlineActionOptions } from '@/lib/inline-action-options';

/**
 * Single choice saves the moment an option is picked, so the radio group
 * submits its own form on change. This mirrors the portal, where selecting
 * also saves.
 *
 * The group is uncontrolled: the browser marks the clicked radio checked
 * before firing change, so `submit()` serialises the new value, and the
 * partial reload then makes the server the source of truth again.
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
    const selectedOptionId = documentRequest.answer_option_ids?.[0] ?? null;

    return (
        <Form
            {...DocumentRequestReviewController.answer.form(routeParameters)}
            options={inlineActionOptions}
        >
            {({ errors, processing, submit }) => (
                <FieldSet className="gap-2">
                    <FieldLegend variant="label" className="mb-0">
                        {t('Client answer')}
                    </FieldLegend>
                    <RadioGroup
                        name="answer_option_ids[]"
                        defaultValue={selectedOptionId ?? undefined}
                        isDisabled={processing}
                        aria-label={t('Client answer')}
                        onChange={() => submit()}
                        className="gap-2"
                    >
                        {options.map((option) => {
                            const radioId = `answer-choice-${documentRequest.id}-${option.id}`;

                            return (
                                <Field key={option.id} orientation="horizontal">
                                    <RadioGroupItem
                                        id={radioId}
                                        value={option.id}
                                    />
                                    <FieldLabel
                                        htmlFor={radioId}
                                        className="cursor-pointer"
                                    >
                                        {option.label}
                                    </FieldLabel>
                                </Field>
                            );
                        })}
                    </RadioGroup>
                    <InputError message={errors.answer_option_ids} />
                </FieldSet>
            )}
        </Form>
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
                        <FieldGroup className="gap-2">
                            {options.map((option) => {
                                const checkboxId = `answer-option-${documentRequest.id}-${option.id}`;

                                return (
                                    <Field
                                        key={option.id}
                                        orientation="horizontal"
                                    >
                                        <Checkbox
                                            id={checkboxId}
                                            name="answer_option_ids[]"
                                            value={option.id}
                                            defaultSelected={selected.has(
                                                option.id,
                                            )}
                                        />
                                        <FieldLabel
                                            htmlFor={checkboxId}
                                            className="cursor-pointer"
                                        >
                                            {option.label}
                                        </FieldLabel>
                                    </Field>
                                );
                            })}
                        </FieldGroup>
                        <InputError message={errors.answer_option_ids} />
                    </FieldSet>
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
