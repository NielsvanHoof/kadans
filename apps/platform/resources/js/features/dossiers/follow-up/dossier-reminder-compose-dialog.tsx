import { Form, useHttp } from '@inertiajs/react';
import { Send, Sparkles } from 'lucide-react';
import { useId, useState } from 'react';
import DossierReminderController from '@/actions/App/Dossiers/Http/Controllers/DossierReminderController';
import DossierReminderDraftController from '@/actions/App/Dossiers/Http/Controllers/DossierReminderDraftController';
import InputError, {
    fieldErrorAriaProps,
} from '@/components/primitives/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Field, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Compose a client reminder: draft with AI, edit freely, then send.
 * The textarea stays controlled so a generated draft can fill it, but its
 * `name="message"` still submits through the Inertia `Form` below like any
 * other field.
 */
export default function DossierReminderComposeDialog({
    dossierId,
}: {
    dossierId: number;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [message, setMessage] = useState('');
    const messageFieldId = useId();
    const messageErrorId = useId();
    const { post: postDraft, processing: drafting } = useHttp<
        Record<string, never>,
        { message: string }
    >({});

    async function generateDraft() {
        const response = await postDraft(
            DossierReminderDraftController.store.url({
                tenant: currentWorkspace,
                dossier: dossierId,
            }),
        );

        setMessage(response.message);
    }

    return (
        <DialogTrigger isOpen={open} onOpenChange={setOpen}>
            <Button type="button">
                <Send aria-hidden="true" />
                {t('Send reminder')}
            </Button>
            <Dialog className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('Compose reminder')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'Generate a friendly draft, then edit it before sending.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...DossierReminderController.store.form({
                        tenant: currentWorkspace,
                        dossier: dossierId,
                    })}
                    onSuccess={() => setOpen(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <InputError message={errors.dossier} />
                            <Field data-invalid={Boolean(errors.message)}>
                                <FieldLabel htmlFor={messageFieldId}>
                                    {t('Message')}
                                </FieldLabel>
                                <Textarea
                                    id={messageFieldId}
                                    name="message"
                                    rows={6}
                                    value={message}
                                    onChange={(event) =>
                                        setMessage(event.target.value)
                                    }
                                    placeholder={t(
                                        'Generate a draft, or write your own message…',
                                    )}
                                    {...fieldErrorAriaProps(
                                        messageErrorId,
                                        errors.message,
                                    )}
                                />
                                <InputError
                                    id={messageErrorId}
                                    message={errors.message}
                                />
                            </Field>

                            <DialogFooter className="gap-2 sm:justify-between">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    isDisabled={drafting}
                                    onClick={generateDraft}
                                >
                                    {drafting ? (
                                        <Spinner />
                                    ) : (
                                        <Sparkles aria-hidden="true" />
                                    )}
                                    {drafting
                                        ? t('Generating…')
                                        : message
                                          ? t('Regenerate draft')
                                          : t('Generate draft')}
                                </Button>
                                <Button type="submit" isDisabled={processing}>
                                    {processing ? (
                                        <Spinner />
                                    ) : (
                                        <Send aria-hidden="true" />
                                    )}
                                    {processing
                                        ? t('Sending…')
                                        : t('Send reminder')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </Dialog>
        </DialogTrigger>
    );
}
