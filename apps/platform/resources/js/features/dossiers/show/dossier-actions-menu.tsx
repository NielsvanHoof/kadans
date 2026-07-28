import { Link } from '@inertiajs/react';
import { Archive, MoreHorizontal, Pencil } from 'lucide-react';
import { useState } from 'react';
import DossierController from '@/actions/App/Dossiers/Http/Controllers/DossierController';
import ConfirmDestroyDialog from '@/components/confirm-destroy-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { edit as editDossier } from '@/routes/workspaces/dossiers';

/**
 * Compact overflow menu for secondary dossier actions (edit / archive).
 * Keeps the page header free for the primary CTA and status badge.
 */
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
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label={t('Actions')}
                        data-test="dossier-actions-menu"
                    >
                        <MoreHorizontal aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="min-w-44">
                    {canEditDetails ? (
                        <DropdownMenuGroup>
                            <DropdownMenuItem asChild>
                                <Link href={editDossier(routeArgs)}>
                                    <Pencil aria-hidden="true" />
                                    {t('Edit dossier')}
                                </Link>
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
                                data-test="archive-dossier-button"
                                onSelect={() => setArchiveOpen(true)}
                            >
                                <Archive aria-hidden="true" />
                                {t('Archive dossier')}
                            </DropdownMenuItem>
                        </DropdownMenuGroup>
                    ) : null}
                </DropdownMenuContent>
            </DropdownMenu>

            <ConfirmDestroyDialog
                open={archiveOpen}
                onOpenChange={setArchiveOpen}
                title={t('Archive :title?', { title: dossierTitle })}
                description={t(
                    'The dossier will be hidden from lists and cannot be opened again. Document requests and files are kept for audit purposes.',
                )}
                confirmLabel={t('Archive dossier')}
                confirmTestId="confirm-archive-dossier-button"
                form={DossierController.destroy.form({
                    tenant: currentWorkspace,
                    dossier: dossierId,
                })}
            />
        </>
    );
}
