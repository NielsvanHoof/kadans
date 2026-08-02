import { RotateCcw } from 'lucide-react';
import DossierController from '@/actions/App/Dossiers/Http/Controllers/DossierController';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import { Button, LinkButton } from '@/components/ui/button';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { archived as archivedClients } from '@/routes/workspaces/clients';

export default function RestoreDossierButton({
    dossierId,
    clientArchived,
    canRestoreClient,
}: {
    dossierId: number;
    clientArchived: boolean;
    canRestoreClient: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    if (clientArchived) {
        return (
            <div className="flex flex-col items-end gap-1">
                {canRestoreClient ? (
                    <LinkButton
                        size="sm"
                        variant="outline"
                        href={archivedClients(currentWorkspace).url}
                    >
                        {t('Restore client first')}
                    </LinkButton>
                ) : null}
                <p className="max-w-52 text-right text-xs text-muted-foreground">
                    {canRestoreClient
                        ? t('This dossier belongs to an archived client.')
                        : t(
                              'Ask a workspace administrator to restore the client first.',
                          )}
                </p>
            </div>
        );
    }

    return (
        <ConfirmDestroyDialog
            title={t('Restore this dossier?')}
            description={t(
                'The dossier will appear in lists again and can be opened by your team.',
            )}
            confirmLabel={t('Restore dossier')}
            confirmVariant="default"
            form={DossierController.restore.form({
                tenant: currentWorkspace,
                dossier: dossierId,
            })}
            trigger={
                <Button type="button" size="sm">
                    <RotateCcw aria-hidden="true" />
                    {t('Restore dossier')}
                </Button>
            }
        />
    );
}
