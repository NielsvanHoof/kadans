import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-shell/app-logo-icon';
import LedgerField from '@/components/brand/ledger-field';
import SkipToMainContent from '@/components/primitives/skip-to-main-content';
import LegalFooterLinks from '@/features/legal/legal-footer-links';
import PortalLocaleSwitcher from '@/features/portal/portal-locale-switcher';
import { useTranslation } from '@/hooks/use-translation';

type PortalShellProps = {
    /** Absent only when a session ended before we ever learned the firm. */
    firmName?: string | null;
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
        <div className="bg-secondary min-h-svh pr-[env(safe-area-inset-right)] pl-[env(safe-area-inset-left)]">
            {/* First tab stop before portal chrome on first load. */}
            <SkipToMainContent />
            <header className="bg-brand-panel text-brand-panel-foreground relative isolate overflow-hidden pt-[env(safe-area-inset-top)]">
                <LedgerField tone="onPanel" />
                <div className="relative z-10 mx-auto flex w-full max-w-2xl flex-col gap-6 px-5 py-8 md:px-8 md:py-10">
                    <div className="flex items-start justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-3">
                            <div className="bg-brand-panel-foreground/15 ring-brand-panel-foreground/20 flex size-11 shrink-0 items-center justify-center rounded-lg ring-1">
                                <AppLogoIcon className="text-brand-panel-foreground size-6" />
                            </div>
                            <div className="min-w-0">
                                <p className="text-brand-panel-foreground/85 text-xs font-medium tracking-wide uppercase">
                                    {t('Secure client portal')}
                                </p>
                                {firmName ? (
                                    <p className="text-brand-panel-foreground truncate text-base">
                                        {firmName}
                                    </p>
                                ) : null}
                            </div>
                        </div>
                        <PortalLocaleSwitcher variant="onPanel" />
                    </div>
                    <div className="space-y-2">
                        <h1 className="font-display text-3xl leading-tight font-semibold tracking-tight text-balance md:text-4xl">
                            {title}
                        </h1>
                        {subtitle ? (
                            <p className="text-brand-panel-foreground/85 text-base text-pretty">
                                {subtitle}
                            </p>
                        ) : null}
                        {meta ? (
                            <p className="text-brand-panel-foreground/85 text-sm">
                                {meta}
                            </p>
                        ) : null}
                    </div>
                </div>
            </header>

            <main
                id="main-content"
                tabIndex={-1}
                className="mx-auto flex w-full max-w-2xl flex-col gap-6 px-5 py-8 md:gap-8 md:px-8 md:py-10"
            >
                {children}
            </main>

            <footer className="mx-auto w-full max-w-2xl px-5 pb-[max(2rem,env(safe-area-inset-bottom))] md:px-8">
                <LegalFooterLinks />
            </footer>
        </div>
    );
}
