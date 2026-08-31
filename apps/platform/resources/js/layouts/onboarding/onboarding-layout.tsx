import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-shell/app-logo-icon';
import BrandHeroPanel from '@/components/brand/brand-hero-panel';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';

/**
 * Auth-style shell for first-run firm setup.
 * Same brand hero as login, but a wider column for the onboarding card.
 */
export default function OnboardingLayout({
    children,
}: {
    children: ReactNode;
}) {
    const { name } = usePage().props;
    const { t } = useTranslation();

    return (
        <div className="relative grid min-h-svh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            <BrandHeroPanel className="relative hidden h-full p-10 lg:flex dark:border-r">
                <Link
                    href={dashboard()}
                    className="flex items-center gap-3 text-lg font-semibold"
                >
                    <AppLogoIcon className="text-brand-panel-foreground size-8" />
                    {name}
                </Link>

                <div className="mt-auto max-w-md space-y-4 pb-4">
                    <p className="text-brand-panel-foreground/85 text-sm font-medium tracking-wide uppercase">
                        {t('Secure document exchange')}
                    </p>
                    <h2 className="font-display text-3xl leading-tight font-semibold text-balance md:text-4xl">
                        {t('The smart, secure way to request client documents')}
                    </h2>
                    <p className="text-brand-panel-foreground/85 text-sm leading-relaxed">
                        {t(
                            'One professional environment for your team and clients to share documents with clarity, structure, and control.',
                        )}
                    </p>
                </div>
            </BrandHeroPanel>

            <div className="w-full lg:p-8">
                <div className="mx-auto flex w-full max-w-xl flex-col justify-center space-y-6">
                    <Link
                        href={dashboard()}
                        aria-label={name}
                        className="relative z-20 flex items-center justify-center lg:hidden"
                    >
                        <div className="bg-primary text-primary-foreground flex size-12 items-center justify-center rounded-md sm:size-14">
                            <AppLogoIcon className="text-primary-foreground size-7 sm:size-8" />
                        </div>
                    </Link>
                    <main id="main-content" tabIndex={-1}>
                        {children}
                    </main>
                </div>
            </div>
        </div>
    );
}
