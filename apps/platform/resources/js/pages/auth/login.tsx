import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { AlertCircle } from 'lucide-react';
import InputError, { fieldErrorAriaProps } from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import StatusMessage from '@/components/status-message';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import PasskeyVerify from '@/features/security/passkeys/passkey-verify';
import { useTranslation } from '@/hooks/use-translation';
import { isLoginThrottleMessage } from '@/lib/is-login-throttle-message';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    const { t } = useTranslation();

    setLayoutProps({
        title: t('Log in to your account'),
        description: t('Enter your email and password below to log in'),
    });

    return (
        <>
            <Head title={t('Log in')} />

            <PasskeyVerify />

            {status && (
                <StatusMessage className="mb-4 text-center">
                    {status}
                </StatusMessage>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => {
                    const throttleMessage =
                        errors.email && isLoginThrottleMessage(errors.email)
                            ? errors.email
                            : null;

                    return (
                        <>
                            {throttleMessage ? (
                                <Alert variant="destructive">
                                    <AlertCircle aria-hidden />
                                    <AlertTitle>
                                        {t('Too many login attempts')}
                                    </AlertTitle>
                                    <AlertDescription>
                                        {throttleMessage}
                                    </AlertDescription>
                                </Alert>
                            ) : null}

                            <FieldGroup className="gap-6">
                                <Field
                                    data-invalid={Boolean(
                                        errors.email && !throttleMessage,
                                    )}
                                >
                                    <FieldLabel htmlFor="email">
                                        {t('Email address')}
                                    </FieldLabel>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        required
                                        autoFocus
                                        tabIndex={0}
                                        autoComplete="email"
                                        placeholder={t('email@example.com')}
                                        {...fieldErrorAriaProps(
                                            'email-error',
                                            throttleMessage
                                                ? undefined
                                                : errors.email,
                                        )}
                                    />
                                    {!throttleMessage ? (
                                        <InputError
                                            id="email-error"
                                            message={errors.email}
                                        />
                                    ) : null}
                                </Field>

                                <Field data-invalid={Boolean(errors.password)}>
                                    <div className="flex items-center">
                                        <FieldLabel htmlFor="password">
                                            {t('Password')}
                                        </FieldLabel>
                                        {canResetPassword && (
                                            <TextLink
                                                href={request()}
                                                className="ml-auto text-sm"
                                                tabIndex={0}
                                            >
                                                {t('Forgot your password?')}
                                            </TextLink>
                                        )}
                                    </div>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
                                        tabIndex={0}
                                        autoComplete="current-password"
                                        placeholder={t('Password')}
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
                                    orientation="horizontal"
                                    className="gap-3"
                                >
                                    <Checkbox
                                        id="remember"
                                        name="remember"
                                        tabIndex={0}
                                    />
                                    <FieldLabel htmlFor="remember">
                                        {t('Remember me')}
                                    </FieldLabel>
                                </Field>

                                <Field>
                                    <Button
                                        type="submit"
                                        className="mt-4 w-full"
                                        tabIndex={0}
                                        disabled={processing}
                                        data-test="login-button"
                                    >
                                        {processing && <Spinner />}
                                        {t('Log in')}
                                    </Button>
                                </Field>
                            </FieldGroup>
                        </>
                    );
                }}
            </Form>
        </>
    );
}
