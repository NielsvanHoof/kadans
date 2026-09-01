import type { UrlMethodPair } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { usePasskeyVerify } from '@laravel/passkeys/react';
import { KeyRound } from 'lucide-react';
import InputError from '@/components/primitives/input-error';
import OrDivider from '@/components/primitives/or-divider';
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

/**
 * File-delivery routes 302 to a signed S3 URL. Inertia XHR following that hop
 * fails CORS because the bucket only allows PUT for uploads. A full page load
 * lets the browser follow the redirect as a navigation instead.
 */
export function isFileDownloadUrl(
    href: string,
    origin: string = window.location.origin,
): boolean {
    try {
        return new URL(href, origin).pathname.endsWith('/download');
    } catch {
        return false;
    }
}

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
            const href = response.redirect ?? '/';

            if (isFileDownloadUrl(href)) {
                window.location.assign(href);

                return;
            }

            router.visit(href);
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
                        isDisabled={isLoading}
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
