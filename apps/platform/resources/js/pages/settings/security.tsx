import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Accounts/Http/Controllers/SecurityController';
import Heading from '@/components/primitives/heading';
import InputError, {
    fieldErrorAriaProps,
} from '@/components/primitives/input-error';
import PasswordInput from '@/components/primitives/password-input';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import type { ManagePasskeysProps } from '@/features/security/passkeys/manage-passkeys';
import ManagePasskeys from '@/features/security/passkeys/manage-passkeys';
import type { ManageTwoFactorProps } from '@/features/security/two-factor/manage-two-factor';
import ManageTwoFactor from '@/features/security/two-factor/manage-two-factor';
import { useTranslation } from '@/hooks/use-translation';
import { edit } from '@/routes/security';

type SecurityProps = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

export default function Security(props: SecurityProps) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Security settings'),
                href: edit(),
            },
        ],
    });

    return (
        <>
            <Head title={t('Security settings')} />

            <h1 className="sr-only">{t('Security settings')}</h1>

            <div className="space-y-6">
                <Heading
                    level={2}
                    variant="small"
                    title={t('Update password')}
                    description={t(
                        'Ensure your account is using a long, random password to stay secure',
                    )}
                />

                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                >
                    {({ errors, processing }) => (
                        <FieldGroup className="gap-6">
                            <Field
                                data-invalid={Boolean(errors.current_password)}
                            >
                                <FieldLabel htmlFor="current_password">
                                    {t('Current password')}
                                </FieldLabel>

                                <PasswordInput
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    name="current_password"
                                    autoComplete="current-password"
                                    placeholder={t('Current password')}
                                    {...fieldErrorAriaProps(
                                        'current-password-error',
                                        errors.current_password,
                                    )}
                                />

                                <InputError
                                    id="current-password-error"
                                    message={errors.current_password}
                                />
                            </Field>

                            <Field data-invalid={Boolean(errors.password)}>
                                <FieldLabel htmlFor="password">
                                    {t('New password')}
                                </FieldLabel>

                                <PasswordInput
                                    id="password"
                                    ref={passwordInput}
                                    name="password"
                                    autoComplete="new-password"
                                    placeholder={t('New password')}
                                    passwordrules={props.passwordRules}
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
                                data-invalid={Boolean(
                                    errors.password_confirmation,
                                )}
                            >
                                <FieldLabel htmlFor="password_confirmation">
                                    {t('Confirm password')}
                                </FieldLabel>

                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    autoComplete="new-password"
                                    placeholder={t('Confirm password')}
                                    passwordrules={props.passwordRules}
                                    {...fieldErrorAriaProps(
                                        'password-confirmation-error',
                                        errors.password_confirmation,
                                    )}
                                />

                                <InputError
                                    id="password-confirmation-error"
                                    message={errors.password_confirmation}
                                />
                            </Field>

                            <Field orientation="horizontal">
                                <Button type="submit" isDisabled={processing}>
                                    {processing && <Spinner />}
                                    {t('Save')}
                                </Button>
                            </Field>
                        </FieldGroup>
                    )}
                </Form>
            </div>

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
            />

            <ManagePasskeys
                canManagePasskeys={props.canManagePasskeys}
                passkeys={props.passkeys}
            />
        </>
    );
}
