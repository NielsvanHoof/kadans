import ClientController from '@/actions/App/Clients/Http/Controllers/ClientController';
import ConfirmDestroyDialog from '@/components/confirm-destroy-dialog';
import { Button } from '@/components/ui/button';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Confirm and submit bulk archive for selected clients.
 * Cascades to each client's live dossiers (same as single archive).
 */
export default function BulkArchiveClientsButton({
    selectedIds,
    totalDossiersCount,
    onSuccess,
}: {
    selectedIds: number[];
    /** Sum of dossiers_count across the selected clients (for confirmation copy). */
    totalDossiersCount: number;
    onSuccess?: () => void;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const count = selectedIds.length;

    if (count === 0) {
        return null;
    }

    return (
        <ConfirmDestroyDialog
            title={
                count === 1
                    ? t('Archive 1 client?')
                    : t('Archive :count clients?', { count })
            }
            description={
                totalDossiersCount > 0
                    ? t(
                          'The selected clients and their :count dossiers will be archived. They will no longer appear in lists.',
                          { count: totalDossiersCount },
                      )
                    : t(
                          'The selected clients will be archived and will no longer appear in lists.',
                      )
            }
            confirmLabel={
                count === 1
                    ? t('Archive client')
                    : t('Archive :count clients', { count })
            }
            confirmTestId="confirm-bulk-archive-clients-button"
            form={{
                ...ClientController.bulkArchive.form(currentWorkspace),
                onSuccess,
            }}
            trigger={
                <Button
                    type="button"
                    size="sm"
                    variant="destructive"
                    data-test="bulk-archive-clients-button"
                >
                    {t('Archive')}
                </Button>
            }
        >
            {selectedIds.map((id) => (
                <input key={id} type="hidden" name="ids[]" value={id} />
            ))}
        </ConfirmDestroyDialog>
    );
}
