import { Form } from '@inertiajs/react';
import { Check, RotateCcw } from 'lucide-react';
import DocumentRequestReviewController from '@/actions/App/Dossiers/Http/Controllers/DocumentRequestReviewController';
import InputError from '@/components/primitives/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import type { DocumentRequest } from '@/features/document-requests/types';
import { useDossierCapabilities } from '@/features/dossiers/permissions/dossier-permissions-context';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import { inlineActionOptions } from '@/lib/inline-action-options';

export default function DocumentRequestReview({
    dossierId,
    documentRequest,
}: {
    dossierId: number;
    documentRequest: DocumentRequest;
}) {
    const { canReview } = useDossierCapabilities();
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const { formatDateTime } = useFormatters();

    if (documentRequest.status === 'rejected') {
        return (
            <Alert variant="destructive">
                <RotateCcw aria-hidden="true" />
                <AlertTitle>{t('Changes requested')}</AlertTitle>
                <AlertDescription>
                    {/* Rejection reason is user-generated — do not translate. */}
                    <p>{documentRequest.rejection_reason}</p>
                    {documentRequest.reviewed_at && (
                        <p>
                            {documentRequest.reviewed_by_name
                                ? t('Reviewed by :name on :date.', {
                                      name: documentRequest.reviewed_by_name,
                                      date: formatDateTime(
                                          documentRequest.reviewed_at,
                                      ),
                                  })
                                : t('Reviewed on :date.', {
                                      date: formatDateTime(
                                          documentRequest.reviewed_at,
                                      ),
                                  })}
                        </p>
                    )}
                </AlertDescription>
            </Alert>
        );
    }

    if (documentRequest.status === 'accepted') {
        return (
            <Alert variant="success">
                <Check aria-hidden="true" />
                <AlertTitle>{t('Approved')}</AlertTitle>
                {documentRequest.reviewed_at && (
                    <AlertDescription>
                        {documentRequest.reviewed_by_name
                            ? `${documentRequest.reviewed_by_name} · `
                            : ''}
                        {formatDateTime(documentRequest.reviewed_at)}
                    </AlertDescription>
                )}
            </Alert>
        );
    }

    if (documentRequest.status !== 'submitted' || !canReview) {
        return null;
    }

    const routeParameters = {
        tenant: currentWorkspace,
        dossier: dossierId,
        documentRequest: documentRequest.id,
    };

    return (
        <div className="bg-muted/20 flex flex-wrap items-center gap-2 rounded-md border px-3 py-3">
            <p className="text-muted-foreground mr-auto text-sm">
                {t('This item is ready for review.')}
            </p>

            <Form
                {...DocumentRequestReviewController.store.form(routeParameters)}
                options={inlineActionOptions}
            >
                {({ processing }) => (
                    <>
                        <input type="hidden" name="decision" value="accepted" />
                        <Button type="submit" size="sm" isDisabled={processing}>
                            <Check aria-hidden="true" />
                            {t('Approve')}
                        </Button>
                    </>
                )}
            </Form>

            <DialogTrigger>
                <Button type="button" size="sm" variant="outline">
                    <RotateCcw aria-hidden="true" />
                    {t('Request changes')}
                </Button>
                <Dialog>
                    <DialogHeader>
                        <DialogTitle>{t('Request changes')}</DialogTitle>
                        <DialogDescription>
                            {t(
                                'Explain exactly what the client should correct or upload again.',
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    <Form
                        {...DocumentRequestReviewController.store.form(
                            routeParameters,
                        )}
                        options={inlineActionOptions}
                    >
                        {({ errors, processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="decision"
                                    value="rejected"
                                />
                                <FieldGroup className="gap-4">
                                    <Field
                                        data-invalid={Boolean(
                                            errors.rejection_reason,
                                        )}
                                    >
                                        <FieldLabel
                                            htmlFor={`rejection-${documentRequest.id}`}
                                        >
                                            {t('Feedback for the client')}
                                        </FieldLabel>
                                        <Textarea
                                            id={`rejection-${documentRequest.id}`}
                                            name="rejection_reason"
                                            rows={4}
                                            required
                                            placeholder={t(
                                                'The document is missing page 2. Please upload the complete statement.',
                                            )}
                                            aria-invalid={Boolean(
                                                errors.rejection_reason,
                                            )}
                                        />
                                        <InputError
                                            message={errors.rejection_reason}
                                        />
                                    </Field>
                                    <Field>
                                        <DialogFooter>
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                isDisabled={processing}
                                            >
                                                {t('Request changes')}
                                            </Button>
                                        </DialogFooter>
                                    </Field>
                                </FieldGroup>
                            </>
                        )}
                    </Form>
                </Dialog>
            </DialogTrigger>
        </div>
    );
}
