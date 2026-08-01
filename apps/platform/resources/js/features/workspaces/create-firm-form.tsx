import { Form } from '@inertiajs/react';
import InputError from '@/components/primitives/input-error';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { RouteFormDefinition } from '@/wayfinder';

/**
 * Firm name form shared by first-run onboarding and the
 * in-app "create a new firm" page. The submit target and
 * button label differ per context.
 */
export default function CreateFirmForm({
    action,
    submitLabel,
}: {
    action: RouteFormDefinition<'post'>;
    submitLabel: string;
}) {
    const { t } = useTranslation();

    return (
        <Form {...action}>
            {({ errors, processing }) => (
                <FieldGroup className="gap-4">
                    <Field data-invalid={Boolean(errors.name)}>
                        <FieldLabel htmlFor="name">{t('Firm name')}</FieldLabel>
                        <Input
                            id="name"
                            name="name"
                            required
                            autoComplete="organization"
                            placeholder={t('Acme Accountants')}
                            autoFocus
                            aria-invalid={Boolean(errors.name)}
                        />
                        <InputError message={errors.name} />
                    </Field>

                    <Field>
                        <Button disabled={processing}>
                            {processing && <Spinner />}
                            {submitLabel}
                        </Button>
                    </Field>
                </FieldGroup>
            )}
        </Form>
    );
}
