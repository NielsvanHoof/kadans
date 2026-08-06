import ClientController from '@/actions/App/Clients/Http/Controllers/ClientController';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import { Button } from '@/components/ui/button';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Archive (soft-delete) a client and cascade to all dossiers.
 */
export default function ArchiveClientButton({
    clientId,
    clientName,
    dossiersCount,
}: {
    clientId: number;
    clientName: string;
    dossiersCount: number;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    return (
        <ConfirmDestroyDialog
            title={t('Archive :name?', { name: clientName })}
            description={
                dossiersCount > 0
                    ? t(
                          'This client and their :count dossiers will be archived. They will no longer appear in lists.',
                          { count: dossiersCount },
                      )
                    : t(
                          'This client will be archived and will no longer appear in lists.',
                      )
            }
            confirmLabel={t('Archive client')}
            form={ClientController.destroy.form({
                tenant: currentWorkspace,
                client: clientId,
            })}
            trigger={
                <Button type="button" size="sm" variant="destructive">
                    {t('Archive')}
                </Button>
            }
        />
    );
}
