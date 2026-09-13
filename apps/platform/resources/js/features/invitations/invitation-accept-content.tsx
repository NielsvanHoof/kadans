import { Form } from '@inertiajs/react';
import AcceptTenantInvitationController from '@/actions/App/Tenancy/Http/Controllers/Invitations/AcceptTenantInvitationController';
import InputError from '@/components/primitives/input-error';
import PasswordInput from '@/components/primitives/password-input';
import StatusMessage from '@/components/primitives/status-message';
import TextLink from '@/components/primitives/text-link';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import type { InvitationShowProps } from '@/features/invitations/types';
import { useTranslation } from '@/hooks/use-translation';
import { login } from '@/routes';

export default function InvitationAcceptContent({
    token,
    firm,
    invitation,
    auth,
    can_register: canRegister,
    passwordRules,
}: InvitationShowProps) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col gap-6">
            <StatusMessage>
                {t(':firm invited :email to join as :role.', {
                    firm: firm.name,
                    email: invitation.email,
                    role: invitation.role_label,
                })}
            </StatusMessage>

            {auth.authenticated && auth.email_matches && (
                <Form
                    {...AcceptTenantInvitationController.accept.form(token)}
                    className="flex flex-col gap-4"
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            className="w-full"
                            isDisabled={processing}
                        >
                            {processing && <Spinner />}
                            {t('Accept invitation')}
                        </Button>
                    )}
                </Form>
            )}

            {auth.authenticated && !auth.email_matches && (
                <div className="text-muted-foreground space-y-3 text-sm">
                    <p>
                        {t(
                            'You are signed in as :email. Sign in with :invite to accept.',
                            {
                                email: auth.user_email ?? '',
                                invite: invitation.email,
                            },
                        )}
                    </p>
                    <TextLink href={login()}>{t('Log in')}</TextLink>
                </div>
            )}

            {!auth.authenticated && (
                <div className="space-y-6">
                    <p className="text-muted-foreground text-sm">
                        {t(
                            'Log in with :email, then come back here to accept the invitation.',
                            { email: invitation.email },
                        )}
                    </p>

                    {canRegister && (
                        <Form
                            {...AcceptTenantInvitationController.register.form(
                                token,
                            )}
                            resetOnSuccess={[
                                'password',
                                'password_confirmation',
                            ]}
                            disableWhileProcessing
                        >
                            {({ processing, errors }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="email"
                                        value={invitation.email}
                                    />

                                    <FieldGroup className="gap-6">
                                        <Field
                                            data-invalid={Boolean(errors.name)}
                                        >
                                            <FieldLabel htmlFor="name">
                                                {t('Name')}
                                            </FieldLabel>
                                            <Input
                                                id="name"
                                                type="text"
                                                required
                                                autoFocus
                                                autoComplete="name"
                                                name="name"
                                                placeholder={t('Full name')}
                                                aria-invalid={Boolean(
                                                    errors.name,
                                                )}
                                            />
                                            <InputError message={errors.name} />
                                        </Field>

                                        <Field data-disabled>
                                            <FieldLabel htmlFor="email">
                                                {t('Email address')}
                                            </FieldLabel>
                                            <Input
                                                id="email"
                                                type="email"
                                                value={invitation.email}
                                                disabled
                                                readOnly
                                            />
                                        </Field>

                                        <Field
                                            data-invalid={Boolean(
                                                errors.password,
                                            )}
                                        >
                                            <FieldLabel htmlFor="password">
                                                {t('Password')}
                                            </FieldLabel>
                                            <PasswordInput
                                                id="password"
                                                required
                                                autoComplete="new-password"
                                                name="password"
                                                placeholder={t('Password')}
                                                passwordrules={passwordRules}
                                                aria-invalid={Boolean(
                                                    errors.password,
                                                )}
                                            />
                                            <InputError
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
                                                required
                                                autoComplete="new-password"
                                                name="password_confirmation"
                                                placeholder={t(
                                                    'Confirm password',
                                                )}
                                                passwordrules={passwordRules}
                                                aria-invalid={Boolean(
                                                    errors.password_confirmation,
                                                )}
                                            />
                                            <InputError
                                                message={
                                                    errors.password_confirmation
                                                }
                                            />
                                        </Field>

                                        <Field>
                                            <Button
                                                type="submit"
                                                className="w-full"
                                                isDisabled={processing}
                                            >
                                                {processing && <Spinner />}
                                                {t('Create account and join')}
                                            </Button>
                                        </Field>
                                    </FieldGroup>
                                </>
                            )}
                        </Form>
                    )}
                </div>
            )}
        </div>
    );
}
