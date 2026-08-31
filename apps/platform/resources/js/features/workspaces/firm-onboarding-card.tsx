import { Building2 } from 'lucide-react';
import Heading from '@/components/primitives/heading';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import CreateFirmForm from '@/features/workspaces/create-firm-form';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { store } from '@/routes/onboarding/firm';

const ONBOARDING_STEPS = [
    { key: 'firm', label: 'Firm' },
    { key: 'client', label: 'Client' },
    { key: 'dossier', label: 'Dossier' },
] as const;

/**
 * First-run card to create the accounting firm.
 */
export default function FirmOnboardingCard() {
    const { t } = useTranslation();

    return (
        <Card className="w-full">
            <CardHeader>
                <Building2 className="text-muted-foreground size-8" />
                <CardTitle>{t('Set up your firm')}</CardTitle>
                <CardDescription>
                    {t(
                        'Start by adding the accounting firm that will manage client dossiers.',
                    )}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
                <nav aria-label={t('Onboarding progress')}>
                    <ol className="flex items-center gap-2">
                        {ONBOARDING_STEPS.map((step, index) => {
                            const isCurrent = index === 0;

                            return (
                                <li
                                    key={step.key}
                                    className="flex min-w-0 flex-1 items-center gap-2"
                                >
                                    <span
                                        className={cn(
                                            'flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-medium',
                                            isCurrent
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'border-border bg-muted text-muted-foreground',
                                        )}
                                        aria-current={
                                            isCurrent ? 'step' : undefined
                                        }
                                    >
                                        {index + 1}
                                    </span>
                                    <span
                                        className={cn(
                                            'truncate text-sm',
                                            isCurrent
                                                ? 'font-medium'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {t(step.label)}
                                    </span>
                                    {index < ONBOARDING_STEPS.length - 1 ? (
                                        <span
                                            className="bg-border hidden h-px flex-1 sm:block"
                                            aria-hidden
                                        />
                                    ) : null}
                                </li>
                            );
                        })}
                    </ol>
                </nav>

                <Heading
                    level={1}
                    title={t('What is your firm called?')}
                    description={t(
                        'Next, you will add your first client and create a dossier.',
                    )}
                />

                <CreateFirmForm
                    action={store.form()}
                    submitLabel={t('Continue to your first client')}
                />
            </CardContent>
        </Card>
    );
}
