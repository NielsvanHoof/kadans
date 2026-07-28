import { Form, Head, setLayoutProps } from '@inertiajs/react';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import InputError, { fieldErrorAriaProps } from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import PasskeyVerify from '@/features/security/passkeys/passkey-verify';
import { useTranslation } from '@/hooks/use-translation';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    const { t } = useTranslation();

    setLayoutProps({
        title: t('Confirm password'),
        description: t(
            'This is a secure area of the application. Please confirm your password before continuing.',
        ),
    });

    return (
        <>
            <Head title={t('Confirm password')} />

            <PasskeyVerify
                routes={{
                    options: confirmOptions(),
                    submit: confirmStore(),
                }}
                label={t('Confirm with passkey')}
                loadingLabel={t('Confirming…')}
                separator={t('Or confirm with password')}
            />

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <FieldGroup className="gap-6">
                        <Field data-invalid={Boolean(errors.password)}>
                            <FieldLabel htmlFor="password">
                                {t('Password')}
                            </FieldLabel>
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder={t('Password')}
                                autoComplete="current-password"
                                autoFocus
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

                        <Field>
                            <Button
                                className="w-full"
                                disabled={processing}
                                data-test="confirm-password-button"
                            >
                                {processing && <Spinner />}
                                {t('Confirm password')}
                            </Button>
                        </Field>
                    </FieldGroup>
                )}
            </Form>
        </>
    );
}
