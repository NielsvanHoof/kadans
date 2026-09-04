import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { AlertCircle } from 'lucide-react';
import OAuthController from '@/actions/App/Integrations/Http/Controllers/OAuthController';
import InputError, {
    fieldErrorAriaProps,
} from '@/components/primitives/input-error';
import OrDivider from '@/components/primitives/or-divider';
import PasswordInput from '@/components/primitives/password-input';
import StatusMessage from '@/components/primitives/status-message';
import TextLink from '@/components/primitives/text-link';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import OAuthProviderButton from '@/features/integrations/oauth-provider-button';
import { isLoginThrottleMessage } from '@/features/security/is-login-throttle-message';
import PasskeyVerify from '@/features/security/passkeys/passkey-verify';
import { useTranslation } from '@/hooks/use-translation';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type LoginProps = {
    status?: string;
    canResetPassword: boolean;
    oauthProviders?: string[];
};

export default function Login({
    status,
    canResetPassword,
    oauthProviders = [],
}: LoginProps) {
    const { t } = useTranslation();

    setLayoutProps({
        title: t('Log in to your account'),
        description: t('Enter your email and password below to log in'),
    });

    const showOAuthProviders = oauthProviders.length > 0;

    const providerLoginLabel = (provider: string): string => {
        if (provider === 'google') {
            return t('Log in with Google');
        }

        if (provider === 'microsoft') {
            return t('Log in with Microsoft');
        }

        return provider;
    };

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
                                            >
                                                {t('Forgot your password?')}
                                            </TextLink>
                                        )}
                                    </div>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
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
                                    <Checkbox id="remember" name="remember" />
                                    <FieldLabel htmlFor="remember">
                                        {t('Remember me')}
                                    </FieldLabel>
                                </Field>

                                <Field>
                                    <Button
                                        type="submit"
                                        className="mt-4 w-full"
                                        isDisabled={processing}
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

            {showOAuthProviders ? (
                <div className="flex flex-col gap-4">
                    <OrDivider label={t('Or continue with')} />
                    <div className="flex flex-col gap-2">
                        {oauthProviders.map((provider) => (
                            <OAuthProviderButton
                                key={provider}
                                provider={provider}
                                href={OAuthController.redirect.url(provider)}
                            >
                                {providerLoginLabel(provider)}
                            </OAuthProviderButton>
                        ))}
                    </div>
                </div>
            ) : null}
        </>
    );
}
