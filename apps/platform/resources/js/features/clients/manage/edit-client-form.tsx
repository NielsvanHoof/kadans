import { Form, Link } from '@inertiajs/react';
import ClientController from '@/actions/App/Http/Controllers/Clients/ClientController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import type { ClientDetails } from '@/features/clients/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { show as showClient } from '@/routes/workspaces/clients';

export default function EditClientForm({
    client,
}: {
    client: Pick<ClientDetails, 'id' | 'name' | 'email'>;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    return (
        <Form
            {...ClientController.update.form({
                tenant: currentWorkspace,
                client: client.id,
            })}
        >
            {({ errors, processing }) => (
                <FieldGroup className="mt-6 gap-6">
                    <Field data-invalid={Boolean(errors.name)}>
                        <FieldLabel htmlFor="name">{t('Name')}</FieldLabel>
                        <Input
                            id="name"
                            name="name"
                            required
                            autoComplete="name"
                            defaultValue={client.name}
                            aria-invalid={Boolean(errors.name)}
                        />
                        <InputError message={errors.name} />
                    </Field>

                    <Field data-invalid={Boolean(errors.email)}>
                        <FieldLabel htmlFor="email">
                            {t('Email address')}
                        </FieldLabel>
                        <Input
                            id="email"
                            name="email"
                            type="email"
                            required
                            autoComplete="email"
                            defaultValue={client.email}
                            aria-invalid={Boolean(errors.email)}
                        />
                        <InputError message={errors.email} />
                    </Field>

                    <Field orientation="horizontal" className="gap-3">
                        <Button disabled={processing}>
                            {processing && <Spinner />}
                            {t('Save changes')}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link
                                href={showClient({
                                    tenant: currentWorkspace,
                                    client: client.id,
                                })}
                            >
                                {t('Cancel')}
                            </Link>
                        </Button>
                    </Field>
                </FieldGroup>
            )}
        </Form>
    );
}
