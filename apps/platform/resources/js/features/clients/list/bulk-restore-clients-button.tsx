import { RotateCcw } from 'lucide-react';
import ClientController from '@/actions/App/Clients/Http/Controllers/ClientController';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import { Button } from '@/components/ui/button';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Confirm and submit bulk restore for selected archived clients.
 */
export default function BulkRestoreClientsButton({
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
                    ? t('Restore 1 client?')
                    : t('Restore :count clients?', { count })
            }
            description={t(
                'The selected clients will appear in lists again. Their dossiers stay archived until restored separately.',
            )}
            confirmLabel={
                count === 1
                    ? t('Restore client')
                    : t('Restore :count clients', { count })
            }
            confirmVariant="default"
            form={{
                ...ClientController.bulkRestore.form(currentWorkspace),
                onSuccess,
            }}
            trigger={
                <Button type="button" size="sm">
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
