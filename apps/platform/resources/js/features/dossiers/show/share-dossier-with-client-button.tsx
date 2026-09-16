import { Form } from '@inertiajs/react';
import { Share2 } from 'lucide-react';
import ShareDossierWithClientController from '@/actions/App/ClientPortal/Http/Controllers/Access/ShareDossierWithClientController';
import { Button } from '@/components/ui/button';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Put a draft dossier in front of the client without minting a new link.
 *
 * The other half of client-level access: the standing link is a door, and this
 * is what decides that this dossier is behind it. Only meaningful while the
 * dossier is a draft — after that it is already shared and cannot go back.
 */
export default function ShareDossierWithClientButton({
    dossierId,
    isDraft,
    hasClientLink,
}: {
    dossierId: number;
    isDraft: boolean;
    /** Whether a standing client link is live, which changes what happens next. */
    hasClientLink: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    if (!isDraft) {
        return null;
    }

    return (
        <div className="border-border/70 bg-card space-y-3 rounded-md border px-4 py-3">
            <p className="text-muted-foreground text-sm text-pretty">
                {hasClientLink
                    ? t(
                          'This dossier is not visible to the client yet. Share it to add it to the link they already have.',
                      )
                    : t(
                          'This dossier is not visible to the client yet. Share it, or create a link below.',
                      )}
            </p>

            <Form
                {...ShareDossierWithClientController.store.form({
                    tenant: currentWorkspace,
                    dossier: dossierId,
                })}
            >
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant="secondary"
                        isDisabled={processing}
                    >
                        <Share2 aria-hidden="true" />
                        {t('Share with client')}
                    </Button>
                )}
            </Form>
        </div>
    );
}
