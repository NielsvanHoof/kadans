import type { UrlMethodPair } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { usePasskeyVerify } from '@laravel/passkeys/react';
import { KeyRound } from 'lucide-react';
import InputError from '@/components/input-error';
import OrDivider from '@/components/or-divider';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    routes?: {
        options: UrlMethodPair;
        submit: UrlMethodPair;
    };
    label?: string;
    loadingLabel?: string;
    separator?: string;
};

export default function PasskeyVerify({
    routes,
    label,
    loadingLabel,
    separator,
}: Props = {}) {
    const { t } = useTranslation();
    const { verify, isLoading, error, isSupported } = usePasskeyVerify({
        ...(routes && {
            routes: {
                options: routes.options.url,
                submit: routes.submit.url,
            },
        }),
        onSuccess: (response) => {
            router.visit(response.redirect ?? '/');
        },
    });

    if (!isSupported) {
        return null;
    }

    return (
        <>
            <FieldGroup className="gap-2">
                <Field data-invalid={Boolean(error)}>
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full"
                        onClick={verify}
                        disabled={isLoading}
                        aria-invalid={Boolean(error)}
                    >
                        {isLoading ? (
                            <Spinner />
                        ) : (
                            <KeyRound className="size-4" />
                        )}
                        {isLoading
                            ? (loadingLabel ?? t('Authenticating…'))
                            : (label ?? t('Sign in with a passkey'))}
                    </Button>
                    {error && (
                        <InputError message={error} className="text-center" />
                    )}
                </Field>
            </FieldGroup>

            <OrDivider
                className="my-6"
                label={separator ?? t('Or continue with email')}
            />
        </>
    );
}
