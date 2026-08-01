import { Form, Head, Link, setLayoutProps, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Accounts/Http/Controllers/ProfileController';
import Heading from '@/components/primitives/heading';
import InputError, {
    fieldErrorAriaProps,
} from '@/components/primitives/input-error';
import StatusMessage from '@/components/primitives/status-message';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import DeleteUser from '@/features/security/delete-user';
import { useTranslation } from '@/hooks/use-translation';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

export default function Profile({
    mustVerifyEmail,
    emailVerified,
    status,
}: {
    mustVerifyEmail: boolean;
    emailVerified: boolean;
    status?: string;
}) {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;
    const { t } = useTranslation();

    if (!user) {
        return null;
    }

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Profile settings'),
                href: edit(),
            },
        ],
    });

    return (
        <>
            <Head title={t('Profile settings')} />

            <h1 className="sr-only">{t('Profile settings')}</h1>

            <div className="space-y-6">
                <Heading
                    level={2}
                    variant="small"
                    title={t('Profile')}
                    description={t('Update your name and email address')}
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                >
                    {({ processing, errors }) => (
                        <FieldGroup className="gap-6">
                            <Field data-invalid={Boolean(errors.name)}>
                                <FieldLabel htmlFor="name">
                                    {t('Name')}
                                </FieldLabel>

                                <Input
                                    id="name"
                                    defaultValue={user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder={t('Full name')}
                                    {...fieldErrorAriaProps(
                                        'name-error',
                                        errors.name,
                                    )}
                                />

                                <InputError
                                    id="name-error"
                                    message={errors.name}
                                />
                            </Field>

                            <Field data-invalid={Boolean(errors.email)}>
                                <FieldLabel htmlFor="email">
                                    {t('Email address')}
                                </FieldLabel>

                                <Input
                                    id="email"
                                    type="email"
                                    defaultValue={user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder={t('Email address')}
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

                            {mustVerifyEmail && !emailVerified && (
                                <Field>
                                    <p className="text-sm text-muted-foreground">
                                        {t('Your email address is unverified.')}{' '}
                                        <Link
                                            href={send()}
                                            as="button"
                                            className="text-foreground underline decoration-border underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!"
                                        >
                                            {t(
                                                'Click here to re-send the verification email.',
                                            )}
                                        </Link>
                                    </p>

                                    {status === 'verification-link-sent' && (
                                        <StatusMessage className="mt-2">
                                            {t(
                                                'A new verification link has been sent to your email address.',
                                            )}
                                        </StatusMessage>
                                    )}
                                </Field>
                            )}

                            <Field orientation="horizontal">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    {processing && <Spinner />}
                                    {t('Save')}
                                </Button>
                            </Field>
                        </FieldGroup>
                    )}
                </Form>
            </div>

            <DeleteUser />
        </>
    );
}
