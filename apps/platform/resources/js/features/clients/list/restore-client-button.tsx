import { RotateCcw } from 'lucide-react';
import ClientController from '@/actions/App/Clients/Http/Controllers/ClientController';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import { Button } from '@/components/ui/button';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';

export default function RestoreClientButton({
    clientId,
    clientName,
}: {
    clientId: number;
    clientName: string;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    return (
        <ConfirmDestroyDialog
            title={t('Restore :name?', { name: clientName })}
            description={t(
                'The client and their dossiers will appear in lists again.',
            )}
            confirmLabel={t('Restore client')}
            confirmVariant="default"
            form={ClientController.restore.form({
                tenant: currentWorkspace,
                client: clientId,
            })}
            trigger={
                <Button type="button" size="sm">
                    <RotateCcw aria-hidden="true" />
                    {t('Restore client')}
                </Button>
            }
        />
    );
}
