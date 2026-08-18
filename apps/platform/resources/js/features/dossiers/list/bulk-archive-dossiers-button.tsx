import { Archive } from 'lucide-react';
import DossierController from '@/actions/App/Dossiers/Http/Controllers/DossierController';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import { Button } from '@/components/ui/button';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Confirm and submit bulk archive for selected dossiers.
 */
export default function BulkArchiveDossiersButton({
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
                    ? t('Archive 1 dossier?')
                    : t('Archive :count dossiers?', { count })
            }
            description={t(
                'The selected dossiers will be archived and will no longer appear in lists.',
            )}
            confirmLabel={
                count === 1
                    ? t('Archive dossier')
                    : t('Archive :count dossiers', { count })
            }
            form={{
                ...DossierController.bulkArchive.form(currentWorkspace),
                onSuccess,
            }}
            trigger={
                <Button type="button" size="sm" variant="destructive">
                    <Archive aria-hidden="true" />
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
