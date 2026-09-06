import { Form } from '@inertiajs/react';
import DossierController from '@/actions/App/Dossiers/Http/Controllers/DossierController';
import EmptyState from '@/components/primitives/empty-state';
import InputError from '@/components/primitives/input-error';
import { Button, LinkButton } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import DossierFollowUpFields from '@/features/dossiers/follow-up/dossier-follow-up-fields';
import type {
    DossierClientOption,
    ResponsibleStaffOption,
} from '@/features/dossiers/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useDefaultReminderIntervalDays } from '@/hooks/use-firm-settings';
import { useTranslation } from '@/hooks/use-translation';
import { index as clientIndex } from '@/routes/workspaces/clients';
import { index as dossierIndex } from '@/routes/workspaces/dossiers';

/**
 * Create-dossier form, or an empty-state when no clients exist yet.
 */
export default function CreateDossierForm({
    clients,
    responsibleStaff,
}: {
    clients: DossierClientOption[];
    responsibleStaff: ResponsibleStaffOption[];
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    // Prefills only here: the edit form passes the dossier's own interval, so a
    // dossier with reminders deliberately off is never handed the firm default.
    const defaultReminderIntervalDays = useDefaultReminderIntervalDays();

    if (clients.length === 0) {
        return (
            <EmptyState
                className="mt-6"
                title={t('Create a client before creating a dossier.')}
                variant="panel"
                bordered
                action={
                    <LinkButton href={clientIndex(currentWorkspace).url}>
                        {t('Go to clients')}
                    </LinkButton>
                }
            />
        );
    }

    return (
        <Form {...DossierController.store.form(currentWorkspace)}>
            {({ errors, processing }) => (
                <FieldGroup className="mt-6 gap-6">
                    <Field data-invalid={Boolean(errors.client_id)}>
                        <FieldLabel htmlFor="client_id">
                            {t('Client')}
                        </FieldLabel>
                        <Select
                            isRequired
                            defaultValue=""
                            name="client_id"
                            placeholder={t('Select a client')}
                            aria-label={t('Select a client')}
                        >
                            <SelectTrigger
                                id="client_id"
                                className="w-full"
                                aria-invalid={Boolean(errors.client_id)}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent className="bg-background space-y-1 p-1">
                                <SelectGroup>
                                    {clients.map((client) => (
                                        <SelectItem
                                            key={client.id}
                                            id={client.id.toString()}
                                            textValue={client.name}
                                            className="flex flex-col"
                                        >
                                            <span className="font-medium">
                                                {client.name}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                {client.email}
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        <InputError message={errors.client_id} />
                    </Field>

                    <Field data-invalid={Boolean(errors.title)}>
                        <FieldLabel htmlFor="title">
                            {t('Dossier name')}
                        </FieldLabel>
                        <Input
                            id="title"
                            name="title"
                            required
                            placeholder={t('2025 payroll documents')}
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
                            placeholder={t('PAY-2025-001')}
                            aria-invalid={Boolean(errors.reference)}
                        />
                        <InputError message={errors.reference} />
                    </Field>

                    <DossierFollowUpFields
                        responsibleStaff={responsibleStaff}
                        defaultReminderIntervalDays={
                            defaultReminderIntervalDays
                        }
                        errors={errors}
                    />

                    <Field orientation="horizontal" className="gap-3">
                        <Button type="submit" isDisabled={processing}>
                            {processing && <Spinner />}
                            {t('Create dossier')}
                        </Button>
                        <LinkButton
                            variant="ghost"
                            href={dossierIndex(currentWorkspace).url}
                        >
                            {t('Cancel')}
                        </LinkButton>
                    </Field>
                </FieldGroup>
            )}
        </Form>
    );
}
