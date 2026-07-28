import { Form, Head, setLayoutProps } from '@inertiajs/react';
import InputError, { fieldErrorAriaProps } from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
    passwordRules: string;
};

export default function ResetPassword({ token, email, passwordRules }: Props) {
    const { t } = useTranslation();

    setLayoutProps({
        title: t('Reset password'),
        description: t('Please enter your new password below'),
    });

    return (
        <>
            <Head title={t('Reset password')} />

            <Form
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <FieldGroup className="gap-6">
                        <Field data-invalid={Boolean(errors.email)}>
                            <FieldLabel htmlFor="email">
                                {t('Email')}
                            </FieldLabel>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="email"
                                value={email}
                                readOnly
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

                        <Field data-invalid={Boolean(errors.password)}>
                            <FieldLabel htmlFor="password">
                                {t('Password')}
                            </FieldLabel>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                autoFocus
                                placeholder={t('Password')}
                                passwordrules={passwordRules}
                                {...fieldErrorAriaProps(
                                    'password-error',
                                    errors.password,
                                )}
                            />
                            <InputError
                                id="password-error"
                                message={errors.password}
                            />
                        </Field>

                        <Field
                            data-invalid={Boolean(errors.password_confirmation)}
                        >
                            <FieldLabel htmlFor="password_confirmation">
                                {t('Confirm password')}
                            </FieldLabel>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                placeholder={t('Confirm password')}
                                passwordrules={passwordRules}
                                {...fieldErrorAriaProps(
                                    'password_confirmation-error',
                                    errors.password_confirmation,
                                )}
                            />
                            <InputError
                                id="password_confirmation-error"
                                message={errors.password_confirmation}
                            />
                        </Field>

                        <Field>
                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                disabled={processing}
                                data-test="reset-password-button"
                            >
                                {processing && <Spinner />}
                                {t('Reset password')}
                            </Button>
                        </Field>
                    </FieldGroup>
                )}
            </Form>
        </>
    );
}
