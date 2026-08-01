import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-shell/app-logo-icon';
import BrandShieldWatermark from '@/components/brand/brand-shield-watermark';
import PortalLocaleSwitcher from '@/features/portal/portal-locale-switcher';
import { useTranslation } from '@/hooks/use-translation';

type PortalShellProps = {
    firmName: string;
    title: string;
    subtitle?: string;
    meta?: string;
    children: ReactNode;
};

/**
 * Branded shell for guest client-portal pages (no staff chrome).
 * Larger, calmer type scale than the staff app — clients often fill this on a phone.
 * The shield motif is structural chrome, not only a background wash.
 */
export default function PortalShell({
    firmName,
    title,
    subtitle,
    meta,
    children,
}: PortalShellProps) {
    const { t } = useTranslation();

    return (
        <div className="min-h-svh bg-secondary pr-[env(safe-area-inset-right)] pl-[env(safe-area-inset-left)]">
            <header className="relative isolate overflow-hidden border-b border-primary/15 bg-primary pt-[env(safe-area-inset-top)] text-primary-foreground">
                {/* Large structural shield — the portal's signature element. */}
                <BrandShieldWatermark
                    variant="light"
                    className="pointer-events-none absolute top-1/2 right-0 h-[140%] w-[55%] max-w-md translate-x-1/4 -translate-y-1/2 opacity-20"
                />
                <BrandShieldWatermark
                    variant="primary"
                    className="pointer-events-none absolute -bottom-12 -left-10 h-56 w-72 opacity-35 md:h-72 md:w-96"
                />
                <div className="relative z-10 mx-auto flex w-full max-w-2xl flex-col gap-6 px-5 py-8 md:px-8 md:py-10">
                    <div className="flex items-start justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-3">
                            <div className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary-foreground/15 ring-1 ring-primary-foreground/20">
                                <AppLogoIcon className="size-6 text-primary-foreground" />
                            </div>
                            <div className="min-w-0">
                                <p className="text-xs font-medium tracking-wide text-primary-foreground/75 uppercase">
                                    {t('Secure client portal')}
                                </p>
                                <p className="truncate text-base text-primary-foreground">
                                    {firmName}
                                </p>
                            </div>
                        </div>
                        <PortalLocaleSwitcher variant="onPrimary" />
                    </div>
                    <div className="space-y-2">
                        <h1 className="font-display text-3xl leading-tight font-semibold tracking-tight text-balance md:text-4xl">
                            {title}
                        </h1>
                        {subtitle ? (
                            <p className="text-base text-pretty text-primary-foreground/85">
                                {subtitle}
                            </p>
                        ) : null}
                        {meta ? (
                            <p className="text-sm text-primary-foreground/70">
                                {meta}
                            </p>
                        ) : null}
                    </div>
                </div>
            </header>

            <main
                id="main-content"
                tabIndex={-1}
                className="mx-auto flex w-full max-w-2xl flex-col gap-6 px-5 py-8 pb-[max(2rem,env(safe-area-inset-bottom))] md:gap-8 md:px-8 md:py-10"
            >
                {children}
            </main>
        </div>
    );
}
