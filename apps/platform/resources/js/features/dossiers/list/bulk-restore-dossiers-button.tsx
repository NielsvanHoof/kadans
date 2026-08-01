import { RotateCcw } from 'lucide-react';
import DossierController from '@/actions/App/Dossiers/Http/Controllers/DossierController';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import { Button } from '@/components/ui/button';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Confirm and submit bulk restore for selected archived dossiers.
 * Callers should only pass IDs whose client is not archived.
 */
export default function BulkRestoreDossiersButton({
    selectedIds,
    onSuccess,
}: {
    selectedIds: number[];
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
                    ? t('Restore 1 dossier?')
                    : t('Restore :count dossiers?', { count })
            }
            description={t(
                'The selected dossiers will appear in lists again and can be opened by your team.',
            )}
            confirmLabel={
                count === 1
                    ? t('Restore dossier')
                    : t('Restore :count dossiers', { count })
            }
            confirmVariant="default"
            confirmTestId="confirm-bulk-restore-dossiers-button"
            form={{
                ...DossierController.bulkRestore.form(currentWorkspace),
                onSuccess,
            }}
            trigger={
                <Button
                    type="button"
                    size="sm"
                    data-test="bulk-restore-dossiers-button"
                >
                    <RotateCcw aria-hidden="true" />
                    {t('Restore')}
                </Button>
            }
        >
            {selectedIds.map((id) => (
                <input key={id} type="hidden" name="ids[]" value={id} />
            ))}
        </ConfirmDestroyDialog>
    );
}
