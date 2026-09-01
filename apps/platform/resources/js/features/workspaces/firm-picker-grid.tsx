import { Link } from '@inertiajs/react';
import { ArrowRight, Building2, Plus } from 'lucide-react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { Firm } from '@/features/workspaces/types';
import { useTranslation } from '@/hooks/use-translation';
import { create as createFirm } from '@/routes/firms';
import { dashboard as workspaceDashboard } from '@/routes/workspaces';

export default function FirmPickerGrid({ firms }: { firms: Firm[] }) {
    const { t } = useTranslation();

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {firms.map((firm) => (
                <Link
                    key={firm.slug}
                    href={workspaceDashboard.url(firm)}
                    className="group focus-visible:ring-ring rounded-xl text-left outline-none focus-visible:ring-2"
                >
                    <Card className="group-hover:bg-muted/50 h-full transition-colors">
                        <CardHeader>
                            <Building2 className="text-muted-foreground size-8" />
                            <CardTitle>{firm.name}</CardTitle>
                            <CardDescription>
                                {t('Open dossiers')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <span className="inline-flex items-center gap-2 text-sm font-medium">
                                {t('Open firm')}
                                <ArrowRight className="size-4" />
                            </span>
                        </CardContent>
                    </Card>
                </Link>
            ))}

            {/* Entry point for creating an additional firm. */}
            <Link
                href={createFirm.url()}
                className="group focus-visible:ring-ring rounded-xl text-left outline-none focus-visible:ring-2"
            >
                <Card className="group-hover:bg-muted/50 h-full border-dashed transition-colors">
                    <CardHeader>
                        <Plus className="text-muted-foreground size-8" />
                        <CardTitle>{t('Add a firm')}</CardTitle>
                        <CardDescription>
                            {t(
                                'Create another firm to manage its own clients and dossiers.',
                            )}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <span className="inline-flex items-center gap-2 text-sm font-medium">
                            {t('Create firm')}
                            <ArrowRight className="size-4" />
                        </span>
                    </CardContent>
                </Card>
            </Link>
        </div>
    );
}
