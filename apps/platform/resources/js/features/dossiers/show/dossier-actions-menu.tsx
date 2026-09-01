import { Archive, MoreHorizontal, Pencil } from 'lucide-react';
import { useState } from 'react';
import DossierController from '@/actions/App/Dossiers/Http/Controllers/DossierController';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { edit as editDossier } from '@/routes/workspaces/dossiers';

export default function DossierActionsMenu({
    dossierId,
    dossierTitle,
    canEditDetails,
    canManage,
}: {
    dossierId: number;
    dossierTitle: string;
    canEditDetails: boolean;
    canManage: boolean;
}) {
    const { t } = useTranslation();
    const currentWorkspace = useCurrentWorkspace();
    // Controlled dialog so archive can open from a dropdown item without nesting.
    const [archiveOpen, setArchiveOpen] = useState(false);

    if (!canEditDetails && !canManage) {
        return null;
    }

    const routeArgs = {
        tenant: currentWorkspace,
        dossier: dossierId,
    };

    return (
        <>
            <DropdownMenuTrigger>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label={t('Actions')}
                >
                    <MoreHorizontal aria-hidden="true" />
                </Button>
                <DropdownMenu placement="bottom end" className="min-w-44">
                    {canEditDetails ? (
                        <DropdownMenuGroup>
                            <DropdownMenuItem href={editDossier(routeArgs).url}>
                                <Pencil aria-hidden="true" />
                                {t('Edit dossier')}
                            </DropdownMenuItem>
                        </DropdownMenuGroup>
                    ) : null}
                    {canEditDetails && canManage ? (
                        <DropdownMenuSeparator />
                    ) : null}
                    {canManage ? (
                        <DropdownMenuGroup>
                            <DropdownMenuItem
                                variant="destructive"
                                onAction={() => setArchiveOpen(true)}
                            >
                                <Archive aria-hidden="true" />
                                {t('Archive dossier')}
                            </DropdownMenuItem>
                        </DropdownMenuGroup>
                    ) : null}
                </DropdownMenu>
            </DropdownMenuTrigger>

            <ConfirmDestroyDialog
                open={archiveOpen}
                onOpenChange={setArchiveOpen}
                title={t('Archive :title?', { title: dossierTitle })}
                description={t(
                    'The dossier will be hidden from lists and cannot be opened again. Document requests and files are kept for audit purposes.',
                )}
                confirmLabel={t('Archive dossier')}
                form={DossierController.destroy.form({
                    tenant: currentWorkspace,
                    dossier: dossierId,
                })}
            />
        </>
    );
}
