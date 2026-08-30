import { Form } from '@inertiajs/react';
import OAuthController from '@/actions/App/Integrations/Http/Controllers/OAuthController';
import { Button, buttonVariants } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

export type OAuthConnectionSummary = {
    provider: string;
    email: string | null;
    has_workspace_scopes?: boolean;
};

export type Props = {
    oauthProviders: string[];
    oauthConnections: OAuthConnectionSummary[];
};

/**
 * Linked SSO providers list for the Integrations settings page.
 * Connect is a full-page GET to Socialite; disconnect is a DELETE.
 */
export default function ManageConnectedAccounts({
    oauthProviders,
    oauthConnections,
}: Props) {
    const { t } = useTranslation();

    const connectionByProvider = Object.fromEntries(
        oauthConnections.map((connection) => [
            connection.provider,
            connection,
        ]),
    );

    const providerLabel = (provider: string): string => {
        if (provider === 'google') {
            return t('Google');
        }

        if (provider === 'microsoft') {
            return t('Microsoft');
        }

        return provider;
    };

    return (
        <div className="overflow-hidden rounded-lg border border-border">
            {oauthProviders.map((provider) => {
                const connection = connectionByProvider[provider];

                return (
                    <div
                        key={provider}
                        className="flex flex-col gap-3 border-b border-border px-4 py-4 last:border-b-0 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div className="space-y-1">
                            <p className="font-medium">
                                {providerLabel(provider)}
                            </p>
                            {connection ? (
                                <p className="text-sm text-muted-foreground">
                                    {connection.email ?? t('Connected')}
                                    {provider === 'google' &&
                                    connection.has_workspace_scopes ===
                                        false
                                        ? ` · ${t('Reconnect for calendar access')}`
                                        : null}
                                </p>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {t('Not connected')}
                                </p>
                            )}
                        </div>

                        {connection ? (
                            <div className="flex flex-wrap gap-2">
                                {provider === 'google' &&
                                connection.has_workspace_scopes === false ? (
                                    <a
                                        className={cn(
                                            buttonVariants({
                                                variant: 'outline',
                                            }),
                                        )}
                                        href={OAuthController.redirect.url(
                                            provider,
                                            { query: { reconsent: 1 } },
                                        )}
                                    >
                                        {t('Reconnect')}
                                    </a>
                                ) : null}
                                <Form
                                    {...OAuthController.destroy.form(provider)}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            isDisabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t('Disconnect')}
                                        </Button>
                                    )}
                                </Form>
                            </div>
                        ) : (
                            /*
                             * Plain <a>, not LinkButton: RouterProvider sends
                             * React Aria hrefs through Inertia XHR. Following the
                             * Socialite 302 to the IdP is then blocked by
                             * connect-src CSP.
                             */
                            <a
                                className={cn(
                                    buttonVariants({ variant: 'outline' }),
                                )}
                                href={OAuthController.redirect.url(provider)}
                            >
                                {t('Connect')}
                            </a>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
