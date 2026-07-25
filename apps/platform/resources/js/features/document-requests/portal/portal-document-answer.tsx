import { Form } from '@inertiajs/react';
import ClientPortalAnswerController from '@/actions/App/Http/Controllers/Portal/ClientPortalAnswerController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { AnswerTextControl } from '@/features/document-requests/controls/answer-text-control';
import type { PortalDocumentRequest } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';
import { inlinePortalActionOptions } from '@/lib/inline-portal-action-options';
import { cn } from '@/lib/utils';

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
            options={inlinePortalActionOptions}
            className="space-y-3"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-2">
                        {/* Visually quiet — the field title above already names the question. */}
                        <Label htmlFor={fieldId} className="sr-only">
                            {t('Your answer')}
                        </Label>
                        <AnswerTextControl
                            id={fieldId}
                            type={documentRequest.type}
                            defaultValue={documentRequest.answer_text ?? ''}
                            placeholder={placeholder}
                        />
                        <InputError message={errors.answer_text} />
                    </div>
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
                </>
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
        <fieldset className="m-0 min-w-0 border-0 p-0">
            <legend className="sr-only">{t('Your answer')}</legend>
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
        </fieldset>
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
            options={inlinePortalActionOptions}
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
