import { Form } from '@inertiajs/react';
import { lazy, Suspense } from 'react';
import ClientPortalAnswerController from '@/actions/App/Http/Controllers/Portal/ClientPortalAnswerController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { AnswerTextControl } from '@/features/document-requests/controls/answer-text-control';
import type { PortalDocumentRequest } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';
import { inlineActionOptions } from '@/lib/inline-action-options';
import { cn } from '@/lib/utils';

const DatePicker = lazy(() => import('@/components/date-picker'));

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
                                        className="h-11 rounded-md border border-input bg-muted/30"
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
                            disabled={processing}
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
 * Portal form for a yes or no questionnaire answer.
 */
export function PortalBooleanDocumentAnswer({
    documentRequest,
}: {
    documentRequest: PortalDocumentRequest;
}) {
    const { t } = useTranslation();

    return (
        <FieldSet className="min-w-0 gap-2">
            <FieldLegend className="sr-only">{t('Your answer')}</FieldLegend>
            <div className="grid grid-cols-2 gap-2">
                <BooleanAnswerButton
                    documentRequestId={documentRequest.id}
                    value={true}
                    label={t('Yes')}
                    active={documentRequest.answer_boolean === true}
                />
                <BooleanAnswerButton
                    documentRequestId={documentRequest.id}
                    value={false}
                    label={t('No')}
                    active={documentRequest.answer_boolean === false}
                />
            </div>
        </FieldSet>
    );
}

function BooleanAnswerButton({
    documentRequestId,
    value,
    label,
    active,
}: {
    documentRequestId: number;
    value: boolean;
    label: string;
    active: boolean;
}) {
    return (
        <Form
            {...ClientPortalAnswerController.store.form({
                documentRequest: documentRequestId,
            })}
            options={inlineActionOptions}
            className="contents"
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
                        disabled={processing}
                        variant={active ? 'default' : 'outline'}
                        className={cn(
                            'h-11 touch-manipulation',
                            active && 'ring-[3px] ring-ring/40',
                        )}
                    >
                        {label}
                    </Button>
                </>
            )}
        </Form>
    );
}
