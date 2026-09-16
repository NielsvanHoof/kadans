import { Form } from '@inertiajs/react';
import { useState } from 'react';
import ClientPortalLinkController from '@/actions/App/ClientPortal/Http/Controllers/Access/ClientPortalLinkController';
import EmptyState from '@/components/primitives/empty-state';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { RevokeAccessGrantButton } from '@/features/portal/client-access-card';
import PortalLinkBanner from '@/features/portal/portal-link-banner';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';

type PortalLink = App.Clients.Data.ClientPortalLinkData;

/**
 * One standing link that opens everything the firm shares with this client.
 *
 * States plainly what the link reaches and that it grows, because a staff
 * member who does not realise that is the actual risk here — the mechanism
 * itself is no weaker than a per-dossier link.
 */
export default function ClientPortalLinkCard({
    clientId,
    clientEmail,
    links,
    canCreate,
    portalUrl,
}: {
    clientId: number;
    clientEmail: string;
    links: PortalLink[];
    canCreate: boolean;
    /** Shown once, right after creation — the plaintext is never stored. */
    portalUrl: string | null;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const { formatDateTime } = useFormatters();
    const [sendInvite, setSendInvite] = useState(true);

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Client portal link')}</CardTitle>
                <CardDescription>
                    {t(
                        'One link that opens every dossier you share with this client. Sharing another dossier adds it to the same link.',
                    )}
                </CardDescription>
            </CardHeader>

            <CardContent className="space-y-4">
                {portalUrl && <PortalLinkBanner portalUrl={portalUrl} />}

                {links.length === 0 ? (
                    <EmptyState title={t('No client link yet.')} />
                ) : (
                    <div className="divide-y rounded-md border">
                        {links.map((link) => (
                            <div
                                key={link.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                            >
                                <div className="text-sm">
                                    <p>
                                        {t('Expires :datetime', {
                                            datetime: formatDateTime(
                                                link.expires_at,
                                            ),
                                        })}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {link.is_valid
                                            ? t('Active')
                                            : link.revoked_at
                                              ? t('Revoked')
                                              : t('Expired')}
                                        {link.is_valid &&
                                            ` · ${t(':count open', {
                                                count: link.shared_dossier_count,
                                            })}`}
                                        {link.last_used_at &&
                                            ` · ${t('Last used :datetime', {
                                                datetime: formatDateTime(
                                                    link.last_used_at,
                                                ),
                                            })}`}
                                    </p>
                                </div>
                                {link.is_valid && (
                                    <RevokeAccessGrantButton
                                        grantId={link.id}
                                    />
                                )}
                            </div>
                        ))}
                    </div>
                )}

                {canCreate && (
                    <Form
                        {...ClientPortalLinkController.store.form({
                            tenant: currentWorkspace,
                            client: clientId,
                        })}
                    >
                        {({ processing }) => (
                            <>
                                {/* Checkbox posts "1" when checked; omitted when unchecked. */}
                                <input
                                    type="hidden"
                                    name="send_invite"
                                    value={sendInvite ? '1' : '0'}
                                />
                                <FieldGroup className="gap-3">
                                    <Field
                                        orientation="horizontal"
                                        className="gap-2"
                                    >
                                        <Checkbox
                                            id="send_client_link_invite"
                                            isSelected={sendInvite}
                                            onChange={(checked) =>
                                                setSendInvite(checked === true)
                                            }
                                        />
                                        <FieldLabel htmlFor="send_client_link_invite">
                                            {t('Email invite to :email', {
                                                email: clientEmail,
                                            })}
                                        </FieldLabel>
                                    </Field>
                                    <Field orientation="horizontal">
                                        <Button
                                            type="submit"
                                            isDisabled={processing}
                                            variant="secondary"
                                        >
                                            {sendInvite
                                                ? t(
                                                      'Create link & email client',
                                                  )
                                                : t('Create client link')}
                                        </Button>
                                    </Field>
                                </FieldGroup>
                            </>
                        )}
                    </Form>
                )}
            </CardContent>
        </Card>
    );
}
