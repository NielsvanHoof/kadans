import { PanelRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Mobile-only status sheet. Desktop already shows the rail beside the
 * workbench; hiding the trigger at `lg` keeps one copy of the facts.
 */
export default function DossierStatusSheet({
    children,
}: {
    children: ReactNode;
}) {
    const { t } = useTranslation();

    return (
        <SheetTrigger>
            <Button type="button" variant="outline" className="lg:hidden">
                <PanelRight aria-hidden="true" />
                {t('Dossier status')}
            </Button>
            <Sheet>
                <SheetHeader>
                    <SheetTitle>{t('Dossier status')}</SheetTitle>
                </SheetHeader>
                <div className="overflow-y-auto overscroll-contain px-6 pb-6">
                    {children}
                </div>
            </Sheet>
        </SheetTrigger>
    );
}
