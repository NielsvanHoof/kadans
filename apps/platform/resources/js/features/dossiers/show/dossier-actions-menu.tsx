import { Archive, CalendarSync, MoreHorizontal, Pencil } from 'lucide-react';
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
import DossierRollOverDialog from '@/features/dossiers/roll-over/dossier-roll-over-dialog';
import type { Dossier } from '@/features/dossiers/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { edit as editDossier } from '@/routes/workspaces/dossiers';

export default function DossierActionsMenu({
    dossier,
    canEditDetails,
    canManage,
    canRollOver,
}: {
    dossier: Pick<
        Dossier,
        'id' | 'title' | 'period_year' | 'period_label' | 'due_date'
    >;
    canEditDetails: boolean;
    canManage: boolean;
    canRollOver: boolean;
}) {
    const { t } = useTranslation();
    const currentWorkspace = useCurrentWorkspace();
    // Controlled dialogs so both can open from a dropdown item without nesting.
    const [archiveOpen, setArchiveOpen] = useState(false);
    const [rollOverOpen, setRollOverOpen] = useState(false);
    const dossierId = dossier.id;

    if (!canEditDetails && !canManage && !canRollOver) {
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
                    {canRollOver ? (
                        <DropdownMenuGroup>
                            <DropdownMenuItem
                                onAction={() => setRollOverOpen(true)}
                            >
                                <CalendarSync aria-hidden="true" />
                                {t('Start next period')}
                            </DropdownMenuItem>
                        </DropdownMenuGroup>
                    ) : null}
                    {(canEditDetails || canRollOver) && canManage ? (
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

            <DossierRollOverDialog
                dossier={dossier}
                open={rollOverOpen}
                onOpenChange={setRollOverOpen}
            />

            <ConfirmDestroyDialog
                open={archiveOpen}
                onOpenChange={setArchiveOpen}
                title={t('Archive :title?', { title: dossier.title })}
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
