import { Form, Head, setLayoutProps } from '@inertiajs/react';
import InputError, {
    fieldErrorAriaProps,
} from '@/components/primitives/input-error';
import StatusMessage from '@/components/primitives/status-message';
import TextLink from '@/components/primitives/text-link';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    const { t } = useTranslation();

    setLayoutProps({
        title: t('Forgot password'),
        description: t('Enter your email to receive a password reset link'),
    });

    return (
        <>
            <Head title={t('Forgot password')} />

            {status && (
                <StatusMessage className="mb-4 text-center">
                    {status}
                </StatusMessage>
            )}

            <div className="space-y-6">
                <Form {...email.form()}>
                    {({ processing, errors }) => (
                        <FieldGroup className="gap-6">
                            <Field data-invalid={Boolean(errors.email)}>
                                <FieldLabel htmlFor="email">
                                    {t('Email address')}
                                </FieldLabel>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    autoComplete="email"
                                    spellCheck={false}
                                    autoFocus
                                    placeholder={t('email@example.com')}
                                    {...fieldErrorAriaProps(
                                        'email-error',
                                        errors.email,
                                    )}
                                />

                                <InputError
                                    id="email-error"
                                    message={errors.email}
                                />
                            </Field>

                            <Field>
                                <Button
                                    type="submit"
                                    className="w-full"
                                    isDisabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {t('Email password reset link')}
                                </Button>
                            </Field>
                        </FieldGroup>
                    )}
                </Form>

                <div className="text-muted-foreground flex justify-center gap-1 text-center text-sm">
                    <span>{t('Or, return to')}</span>{' '}
                    <TextLink href={login()}>{t('log in')}</TextLink>
                </div>
            </div>
        </>
    );
}
