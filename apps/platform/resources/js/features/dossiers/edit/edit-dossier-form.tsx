import { Form } from '@inertiajs/react';
import DossierController from '@/actions/App/Dossiers/Http/Controllers/Lifecycle/DossierController';
import InputError from '@/components/primitives/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button, LinkButton } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import DossierFollowUpFields from '@/features/dossiers/follow-up/dossier-follow-up-fields';
import type {
    EditableDossierWithResponsibility,
    ResponsibleStaffOption,
} from '@/features/dossiers/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { show as showDossier } from '@/routes/workspaces/dossiers';

export default function EditDossierForm({
    dossier,
    responsibleStaff,
}: {
    dossier: EditableDossierWithResponsibility;
    responsibleStaff: ResponsibleStaffOption[];
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    return (
        <Form
            {...DossierController.update.form({
                tenant: currentWorkspace,
                dossier: dossier.id,
            })}
        >
            {({ errors, processing }) => (
                <div className="mt-6 flex flex-col gap-6">
                    <Alert>
                        <AlertTitle>{t('Client')}</AlertTitle>
                        <AlertDescription>
                            <p className="text-foreground font-medium">
                                {dossier.client.name}
                            </p>
                            <p>{dossier.client.email}</p>
                            <p>
                                {t(
                                    'The client cannot be changed after a dossier is created.',
                                )}
                            </p>
                        </AlertDescription>
                    </Alert>

                    <FieldGroup className="gap-6">
                        <Field data-invalid={Boolean(errors.title)}>
                            <FieldLabel htmlFor="title">
                                {t('Dossier name')}
                            </FieldLabel>
                            <Input
                                id="title"
                                name="title"
                                required
                                defaultValue={dossier.title}
                                aria-invalid={Boolean(errors.title)}
                            />
                            <InputError message={errors.title} />
                        </Field>

                        <Field data-invalid={Boolean(errors.reference)}>
                            <FieldLabel htmlFor="reference">
                                {t('Reference (optional)')}
                            </FieldLabel>
                            <Input
                                id="reference"
                                name="reference"
                                defaultValue={dossier.reference ?? ''}
                                aria-invalid={Boolean(errors.reference)}
                            />
                            <InputError message={errors.reference} />
                        </Field>

                        <DossierFollowUpFields
                            responsibleStaff={responsibleStaff}
                            defaultDueDate={dossier.due_date ?? ''}
                            defaultResponsibleUserId={
                                dossier.responsible_user_id
                            }
                            defaultReminderIntervalDays={
                                dossier.reminder_interval_days
                            }
                            errors={errors}
                        />

                        <Field orientation="horizontal" className="gap-3">
                            <Button type="submit" isDisabled={processing}>
                                {processing && <Spinner />}
                                {t('Save changes')}
                            </Button>
                            <LinkButton
                                variant="ghost"
                                href={
                                    showDossier({
                                        tenant: currentWorkspace,
                                        dossier: dossier.id,
                                    }).url
                                }
                            >
                                {t('Cancel')}
                            </LinkButton>
                        </Field>
                    </FieldGroup>
                </div>
            )}
        </Form>
    );
}
