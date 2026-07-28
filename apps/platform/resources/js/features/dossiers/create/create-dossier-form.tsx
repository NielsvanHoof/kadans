import { Form, Link } from '@inertiajs/react';
import DossierController from '@/actions/App/Http/Controllers/Dossiers/DossierController';
import EmptyState from '@/components/empty-state';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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

    if (clients.length === 0) {
        return (
            <EmptyState
                className="mt-6"
                title={t('Create a client before creating a dossier.')}
                variant="panel"
                bordered
                action={
                    <Button asChild>
                        <Link href={clientIndex(currentWorkspace)}>
                            {t('Go to clients')}
                        </Link>
                    </Button>
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
                        <Select required defaultValue="" name="client_id">
                            <SelectTrigger
                                id="client_id"
                                className="w-full"
                                aria-invalid={Boolean(errors.client_id)}
                            >
                                <SelectValue
                                    placeholder={t('Select a client')}
                                />
                            </SelectTrigger>
                            <SelectContent className="space-y-1 bg-background p-1">
                                <SelectGroup>
                                    {clients.map((client) => (
                                        <SelectItem
                                            key={client.id}
                                            value={client.id.toString()}
                                            className="flex flex-col"
                                        >
                                            <span className="font-medium">
                                                {client.name}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
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
                        errors={errors}
                    />

                    <Field orientation="horizontal" className="gap-3">
                        <Button disabled={processing}>
                            {processing && <Spinner />}
                            {t('Create dossier')}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={dossierIndex(currentWorkspace)}>
                                {t('Cancel')}
                            </Link>
                        </Button>
                    </Field>
                </FieldGroup>
            )}
        </Form>
    );
}
