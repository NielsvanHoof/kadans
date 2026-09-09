import { Form, router } from '@inertiajs/react';
import { useState } from 'react';
import ClientAccessGrantController from '@/actions/App/ClientPortal/Http/Controllers/Access/ClientAccessGrantController';
import EmptyState from '@/components/primitives/empty-state';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import type { AccessGrant } from '@/features/portal/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import { inlineActionOptions } from '@/lib/inline-action-options';

/**
 * Lists access grants and creates a new 7-day portal link (optionally emailed).
 * The dossier workbench names and describes the panel; this renders the body.
 */
export default function ClientAccessCard({
    dossierId,
    clientEmail,
    accessGrants,
    canCreate,
}: {
    dossierId: number;
    clientEmail: string;
    accessGrants: AccessGrant[];
    canCreate: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const { formatDateTime } = useFormatters();
    const [sendInvite, setSendInvite] = useState(true);

    return (
        <div className="space-y-4">
            {accessGrants.length === 0 ? (
                <EmptyState title={t('No portal links yet.')} />
            ) : (
                <div className="divide-y rounded-md border">
                    {accessGrants.map((grant) => (
                        <div
                            key={grant.id}
                            className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                        >
                            <div className="text-sm">
                                <p>
                                    {t('Expires :datetime', {
                                        datetime: formatDateTime(
                                            grant.expires_at,
                                        ),
                                    })}
                                </p>
                                <p className="text-muted-foreground">
                                    {grant.is_valid
                                        ? t('Active')
                                        : grant.revoked_at
                                          ? t('Revoked')
                                          : t('Expired')}
                                    {grant.last_used_at &&
                                        ` · ${t('Last used :datetime', {
                                            datetime: formatDateTime(
                                                grant.last_used_at,
                                            ),
                                        })}`}
                                </p>
                            </div>
                            {grant.is_valid && (
                                <RevokeAccessGrantButton grantId={grant.id} />
                            )}
                        </div>
                    ))}
                </div>
            )}

            {canCreate && (
                <Form
                    {...ClientAccessGrantController.store.form({
                        tenant: currentWorkspace,
                        dossier: dossierId,
                    })}
                >
                    {({ processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="expires_in_days"
                                value="7"
                            />
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
                                        id="send_invite"
                                        isSelected={sendInvite}
                                        onChange={(checked) =>
                                            setSendInvite(checked === true)
                                        }
                                    />
                                    <FieldLabel htmlFor="send_invite">
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
                                            ? t('Create link & email client')
                                            : t('Create 7-day portal link')}
                                    </Button>
                                </Field>
                            </FieldGroup>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}

function RevokeAccessGrantButton({ grantId }: { grantId: number }) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const [processing, setProcessing] = useState(false);

    return (
        <DialogTrigger>
            <Button type="button" variant="outline" size="sm">
                {t('Revoke')}
            </Button>
            <Dialog>
                <DialogHeader>
                    <DialogTitle>{t('Revoke portal access?')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'The client will no longer be able to use this link. You can create a new one anytime.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2">
                    <DialogClose
                        type="button"
                        variant="secondary"
                        isDisabled={processing}
                    >
                        {t('Cancel')}
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        isDisabled={processing}
                        onClick={() => {
                            setProcessing(true);
                            router.delete(
                                ClientAccessGrantController.destroy.url({
                                    tenant: currentWorkspace,
                                    grant: grantId,
                                }),
                                {
                                    ...inlineActionOptions,
                                    onFinish: () => setProcessing(false),
                                },
                            );
                        }}
                    >
                        {t('Revoke access')}
                    </Button>
                </DialogFooter>
            </Dialog>
        </DialogTrigger>
    );
}
