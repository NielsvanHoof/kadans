import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-shell/app-logo-icon';
import LedgerField from '@/components/brand/ledger-field';
import SkipToMainContent from '@/components/primitives/skip-to-main-content';
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
 * The ruled ledger field carries the brand; the header itself stays quiet.
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
            {/* First tab stop before portal chrome on first load. */}
            <SkipToMainContent />
            <header className="relative isolate overflow-hidden bg-brand-panel pt-[env(safe-area-inset-top)] text-brand-panel-foreground">
                <LedgerField tone="onPanel" />
                <div className="relative z-10 mx-auto flex w-full max-w-2xl flex-col gap-6 px-5 py-8 md:px-8 md:py-10">
                    <div className="flex items-start justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-3">
                            <div className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-brand-panel-foreground/15 ring-1 ring-brand-panel-foreground/20">
                                <AppLogoIcon className="size-6 text-brand-panel-foreground" />
                            </div>
                            <div className="min-w-0">
                                <p className="text-xs font-medium tracking-wide text-brand-panel-foreground/85 uppercase">
                                    {t('Secure client portal')}
                                </p>
                                <p className="truncate text-base text-brand-panel-foreground">
                                    {firmName}
                                </p>
                            </div>
                        </div>
                        <PortalLocaleSwitcher variant="onPanel" />
                    </div>
                    <div className="space-y-2">
                        <h1 className="font-display text-3xl leading-tight font-semibold tracking-tight text-balance md:text-4xl">
                            {title}
                        </h1>
                        {subtitle ? (
                            <p className="text-base text-pretty text-brand-panel-foreground/85">
                                {subtitle}
                            </p>
                        ) : null}
                        {meta ? (
                            <p className="text-sm text-brand-panel-foreground/85">
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
