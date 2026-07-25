import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { AlertCircle } from 'lucide-react';
import InputError, { fieldErrorAriaProps } from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import StatusMessage from '@/components/status-message';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

                            <div className="grid gap-6">
                                <div className="grid gap-2">
                                    <Label htmlFor="email">
                                        {t('Email address')}
                                    </Label>
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
                                </div>

                                <div className="grid gap-2">
                                    <div className="flex items-center">
                                        <Label htmlFor="password">
                                            {t('Password')}
                                        </Label>
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
                                </div>

                                <div className="flex items-center space-x-3">
                                    <Checkbox
                                        id="remember"
                                        name="remember"
                                        tabIndex={0}
                                    />
                                    <Label htmlFor="remember">
                                        {t('Remember me')}
                                    </Label>
                                </div>

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
                            </div>
                        </>
                    );
                }}
            </Form>
        </>
    );
}
