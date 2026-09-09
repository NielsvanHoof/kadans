import { Form } from '@inertiajs/react';
import WorkspaceSettingsController from '@/actions/App/Tenancy/Http/Controllers/Settings/WorkspaceSettingsController';
import InputError, {
    fieldErrorAriaProps,
} from '@/components/primitives/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
    InputGroupText,
} from '@/components/ui/input-group';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { Firm } from '@/features/workspaces/types';

type FirmIdentitySectionProps = {
    firm: Firm;
};

/**
 * Its own form: this writes the firm record rather than its settings, and a
 * successful save moves the page to a different address.
 */
export default function FirmIdentitySection({
    firm,
}: FirmIdentitySectionProps) {
    const { t } = useTranslation();

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Firm details')}</CardTitle>
                <CardDescription>
                    {t(
                        'The name your team and your clients see, and the web address your firm works from.',
                    )}
                </CardDescription>
            </CardHeader>

            <CardContent>
                <Form
                    {...WorkspaceSettingsController.updateFirm.form({
                        tenant: firm,
                    })}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <FieldGroup className="gap-6">
                            <Field data-invalid={Boolean(errors.name)}>
                                <FieldLabel htmlFor="firm-name">
                                    {t('Firm name')}
                                </FieldLabel>

                                <Input
                                    id="firm-name"
                                    name="name"
                                    defaultValue={firm.name}
                                    required
                                    maxLength={120}
                                    {...fieldErrorAriaProps(
                                        'firm-name-error',
                                        errors.name,
                                    )}
                                />

                                <InputError
                                    id="firm-name-error"
                                    message={errors.name}
                                />
                            </Field>

                            <Field data-invalid={Boolean(errors.slug)}>
                                <FieldLabel htmlFor="firm-slug">
                                    {t('Web address')}
                                </FieldLabel>

                                <InputGroup>
                                    <InputGroupAddon align="inline-start">
                                        <InputGroupText>
                                            /workspaces/
                                        </InputGroupText>
                                    </InputGroupAddon>

                                    <InputGroupInput
                                        id="firm-slug"
                                        name="slug"
                                        defaultValue={firm.slug}
                                        required
                                        {...fieldErrorAriaProps(
                                            'firm-slug-error',
                                            errors.slug,
                                        )}
                                    />
                                </InputGroup>

                                <FieldDescription>
                                    {t(
                                        'Letters, numbers, and hyphens. Anything else is tidied up when you save.',
                                    )}
                                </FieldDescription>

                                <InputError
                                    id="firm-slug-error"
                                    message={errors.slug}
                                />
                            </Field>

                            <Alert>
                                <AlertTitle>
                                    {t(
                                        'Changing the web address breaks saved links',
                                    )}
                                </AlertTitle>
                                <AlertDescription>
                                    {t(
                                        'Bookmarks and links your team has already saved will stop working. Everyone will need to reach the firm from the switcher once, then save the new address.',
                                    )}
                                </AlertDescription>
                            </Alert>

                            <Field orientation="horizontal">
                                <Button type="submit" isDisabled={processing}>
                                    {processing ? <Spinner /> : null}
                                    {processing
                                        ? t('Saving…')
                                        : t('Save firm details')}
                                </Button>
                            </Field>
                        </FieldGroup>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}
