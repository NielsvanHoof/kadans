import { Form } from '@inertiajs/react';
import ClientController from '@/actions/App/Clients/Http/Controllers/ClientController';
import InputError from '@/components/primitives/input-error';
import { Button, LinkButton } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { index as clientIndex } from '@/routes/workspaces/clients';

/**
 * Form to create a new client (name + email).
 */
export default function CreateClientForm() {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    return (
        <Form {...ClientController.store.form(currentWorkspace)}>
            {({ errors, processing }) => (
                <FieldGroup className="mt-6 gap-6">
                    <Field data-invalid={Boolean(errors.name)}>
                        <FieldLabel htmlFor="name">{t('Name')}</FieldLabel>
                        <Input
                            id="name"
                            name="name"
                            required
                            autoComplete="name"
                            placeholder={t('Jane Client')}
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
                            placeholder={t('jane@example.com')}
                            aria-invalid={Boolean(errors.email)}
                        />
                        <InputError message={errors.email} />
                    </Field>

                    <Field orientation="horizontal" className="gap-3">
                        <Button type="submit" isDisabled={processing}>
                            {processing && <Spinner />}
                            {t('Create client')}
                        </Button>
                        <LinkButton
                            variant="ghost"
                            href={clientIndex(currentWorkspace).url}
                        >
                            {t('Cancel')}
                        </LinkButton>
                    </Field>
                </FieldGroup>
            )}
        </Form>
    );
}
