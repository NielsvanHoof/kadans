import { Form } from '@inertiajs/react';
import { lazy, Suspense } from 'react';
import ClientPortalAnswerController from '@/actions/App/ClientPortal/Http/Controllers/ClientPortalAnswerController';
import InputError from '@/components/primitives/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
    FieldTitle,
} from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { AnswerTextControl } from '@/features/document-requests/controls/answer-text-control';
import type { PortalDocumentRequest } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';
import { inlineActionOptions } from '@/lib/inline-action-options';

const DatePicker = lazy(() => import('@/components/forms/date-picker'));

/**
 * Portal form for answer_text types (text, textarea, date, number).
 */
export function PortalTextDocumentAnswer({
    documentRequest,
}: {
    documentRequest: PortalDocumentRequest;
}) {
    const { t } = useTranslation();
    const fieldId = `answer-${documentRequest.id}`;
    const placeholder =
        documentRequest.type === 'number'
            ? t('Enter a number…')
            : t('Write your answer…');

    return (
        <Form
            {...ClientPortalAnswerController.store.form({
                documentRequest: documentRequest.id,
            })}
            options={inlineActionOptions}
        >
            {({ errors, processing }) => (
                <FieldGroup className="gap-3">
                    <Field data-invalid={Boolean(errors.answer_text)}>
                        {/* Visually quiet — the field title above already names the question. */}
                        <FieldLabel htmlFor={fieldId} className="sr-only">
                            {t('Your answer')}
                        </FieldLabel>
                        {documentRequest.type === 'date' ? (
                            <Suspense
                                fallback={
                                    <div
                                        className="border-input bg-muted/30 h-11 rounded-md border"
                                        aria-hidden="true"
                                    />
                                }
                            >
                                <DatePicker
                                    id={fieldId}
                                    name="answer_text"
                                    defaultValue={
                                        documentRequest.answer_text ?? ''
                                    }
                                    invalid={Boolean(errors.answer_text)}
                                    required
                                    triggerClassName="h-11"
                                />
                            </Suspense>
                        ) : (
                            <AnswerTextControl
                                id={fieldId}
                                type={documentRequest.type}
                                defaultValue={documentRequest.answer_text ?? ''}
                                placeholder={placeholder}
                                aria-invalid={Boolean(errors.answer_text)}
                            />
                        )}
                        <InputError message={errors.answer_text} />
                    </Field>
                    <Field orientation="horizontal">
                        <Button
                            type="submit"
                            isDisabled={processing}
                            className="w-full sm:w-auto"
                        >
                            {processing
                                ? t('Saving…')
                                : documentRequest.answer_text
                                  ? t('Update answer')
                                  : t('Submit answer')}
                        </Button>
                    </Field>
                </FieldGroup>
            )}
        </Form>
    );
}

/**
 * A tappable radio row: the label wraps a Field, which shadcn renders as a
 * bordered card that tints when selected. Keeps the large touch target the
 * portal needs on mobile while giving the group real radio semantics —
 * roving focus and arrow-key navigation, which the old Button grid lacked.
 */
function PortalRadioCard({
    id,
    value,
    label,
}: {
    id: string;
    value: string;
    label: string;
}) {
    return (
        <FieldLabel htmlFor={id}>
            <Field orientation="horizontal" className="min-h-11 items-center">
                <RadioGroupItem id={id} value={value} />
                <FieldTitle className="text-base">{label}</FieldTitle>
            </Field>
        </FieldLabel>
    );
}

/**
 * Portal form for a yes or no questionnaire answer.
 *
 * One form for the pair rather than one per button. The group is uncontrolled:
 * the browser marks the clicked radio checked before firing change, so
 * `submit()` serialises the new value and the partial reload then makes the
 * server the source of truth again.
 */
export function PortalBooleanDocumentAnswer({
    documentRequest,
}: {
    documentRequest: PortalDocumentRequest;
}) {
    const { t } = useTranslation();
    const answer = documentRequest.answer_boolean;

    return (
        <Form
            {...ClientPortalAnswerController.store.form({
                documentRequest: documentRequest.id,
            })}
            options={inlineActionOptions}
        >
            {({ processing, submit }) => (
                <FieldSet className="min-w-0 gap-2">
                    <FieldLegend className="sr-only">
                        {t('Your answer')}
                    </FieldLegend>
                    <RadioGroup
                        name="answer_boolean"
                        defaultValue={
                            answer === null || answer === undefined
                                ? undefined
                                : answer
                                  ? '1'
                                  : '0'
                        }
                        isDisabled={processing}
                        aria-label={t('Your answer')}
                        onChange={() => submit()}
                        className="grid-cols-2 gap-2 sm:grid"
                    >
                        <PortalRadioCard
                            id={`answer-${documentRequest.id}-yes`}
                            value="1"
                            label={t('Yes')}
                        />
                        <PortalRadioCard
                            id={`answer-${documentRequest.id}-no`}
                            value="0"
                            label={t('No')}
                        />
                    </RadioGroup>
                </FieldSet>
            )}
        </Form>
    );
}

/**
 * Single choice — selecting an option saves immediately and refreshes the form.
 */
export function PortalChoiceDocumentAnswer({
    documentRequest,
}: {
    documentRequest: PortalDocumentRequest;
}) {
    const { t } = useTranslation();
    const options = documentRequest.configuration?.options ?? [];

    return (
        <Form
            {...ClientPortalAnswerController.store.form({
                documentRequest: documentRequest.id,
            })}
            options={inlineActionOptions}
        >
            {({ processing, submit }) => (
                <FieldSet className="min-w-0 gap-2">
                    <FieldLegend className="sr-only">
                        {t('Your answer')}
                    </FieldLegend>
                    <RadioGroup
                        name="answer_option_ids[]"
                        defaultValue={
                            documentRequest.answer_option_ids?.[0] ?? undefined
                        }
                        isDisabled={processing}
                        aria-label={t('Your answer')}
                        onChange={() => submit()}
                        className="gap-2"
                    >
                        {options.map((option) => (
                            <PortalRadioCard
                                key={option.id}
                                id={`answer-${documentRequest.id}-${option.id}`}
                                value={option.id}
                                label={option.label}
                            />
                        ))}
                    </RadioGroup>
                </FieldSet>
            )}
        </Form>
    );
}

/**
 * Multi-select — client picks one or more options, then submits.
 */
export function PortalMultiSelectDocumentAnswer({
    documentRequest,
}: {
    documentRequest: PortalDocumentRequest;
}) {
    const { t } = useTranslation();
    const options = documentRequest.configuration?.options ?? [];
    const selected = new Set(documentRequest.answer_option_ids ?? []);

    return (
        <Form
            {...ClientPortalAnswerController.store.form({
                documentRequest: documentRequest.id,
            })}
            options={inlineActionOptions}
        >
            {({ errors, processing }) => (
                <FieldGroup className="gap-3">
                    <FieldSet className="min-w-0 gap-2">
                        <FieldLegend className="sr-only">
                            {t('Your answer')}
                        </FieldLegend>
                        <FieldGroup className="gap-2">
                            {options.map((option) => {
                                const checkboxId = `answer-${documentRequest.id}-${option.id}`;

                                return (
                                    <FieldLabel
                                        key={option.id}
                                        htmlFor={checkboxId}
                                    >
                                        <Field
                                            orientation="horizontal"
                                            className="min-h-11 items-center"
                                        >
                                            <Checkbox
                                                id={checkboxId}
                                                name="answer_option_ids[]"
                                                value={option.id}
                                                defaultSelected={selected.has(
                                                    option.id,
                                                )}
                                            />
                                            <FieldTitle className="text-base">
                                                {option.label}
                                            </FieldTitle>
                                        </Field>
                                    </FieldLabel>
                                );
                            })}
                        </FieldGroup>
                        <InputError message={errors.answer_option_ids} />
                    </FieldSet>
                    <Field orientation="horizontal">
                        <Button
                            type="submit"
                            isDisabled={processing}
                            className="w-full sm:w-auto"
                        >
                            {processing
                                ? t('Saving…')
                                : selected.size > 0
                                  ? t('Update answer')
                                  : t('Submit answer')}
                        </Button>
                    </Field>
                </FieldGroup>
            )}
        </Form>
    );
}
