import { Head, Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-shell/app-logo-icon';
import LegalFooterLinks from '@/features/legal/legal-footer-links';
import { useTranslation } from '@/hooks/use-translation';
import { show as legalShow } from '@/routes/legal';
import { dashboard } from '@/routes';

type LegalNavItem = {
    slug: string;
    title: string;
};

type Props = {
    document: string;
    title: string;
    html: string;
    is_draft: boolean;
    documents: LegalNavItem[];
};

/**
 * Public legal document page (privacy, cookies, terms).
 * Renders server-sanitised HTML from packaged markdown.
 */
export default function LegalShow({
    document,
    title,
    html,
    is_draft: isDraft,
    documents,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={title} />

            <div className="min-h-svh bg-background">
                <header className="border-b border-border/80">
                    <div className="mx-auto flex w-full max-w-3xl items-center justify-between gap-4 px-5 py-5 md:px-8">
                        <Link
                            href={dashboard.url()}
                            className="flex items-center gap-2 text-sm font-medium"
                            aria-label={t('Privacy policy')}
                        >
                            <span className="flex size-9 items-center justify-center rounded-md bg-primary text-primary-foreground">
                                <AppLogoIcon className="size-5 text-primary-foreground" />
                            </span>
                        </Link>
                        <nav
                            aria-label={t('Legal documents')}
                            className="flex flex-wrap items-center justify-end gap-x-4 gap-y-2 text-sm"
                        >
                            {documents.map((item) => (
                                <Link
                                    key={item.slug}
                                    href={legalShow.url(item.slug)}
                                    className={
                                        item.slug === document
                                            ? 'font-medium text-foreground'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }
                                    aria-current={
                                        item.slug === document
                                            ? 'page'
                                            : undefined
                                    }
                                >
                                    {item.title}
                                </Link>
                            ))}
                        </nav>
                    </div>
                </header>

                <main
                    id="main-content"
                    tabIndex={-1}
                    className="mx-auto w-full max-w-3xl px-5 py-10 md:px-8 md:py-14"
                >
                    {isDraft ? (
                        <p
                            role="status"
                            className="mb-6 rounded-md border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-950 dark:text-amber-100"
                        >
                            {t(
                                'This document is a draft pending legal review. Placeholders must be completed by counsel before relying on it.',
                            )}
                        </p>
                    ) : null}

                    {/* HTML is produced by Str::markdown with html_input strip. */}
                    <article
                        className="legal-prose space-y-4 text-sm leading-relaxed text-foreground [&_a]:text-primary [&_a]:underline [&_h1]:font-display [&_h1]:text-3xl [&_h1]:font-semibold [&_h1]:tracking-tight [&_h2]:mt-8 [&_h2]:text-xl [&_h2]:font-semibold [&_li]:ms-5 [&_li]:list-disc [&_p]:text-pretty [&_table]:w-full [&_table]:border-collapse [&_td]:border [&_td]:border-border [&_td]:px-2 [&_td]:py-1.5 [&_th]:border [&_th]:border-border [&_th]:px-2 [&_th]:py-1.5 [&_th]:text-left"
                        // biome-ignore lint/security/noDangerouslySetInnerHtml: sanitised markdown from app resources
                        dangerouslySetInnerHTML={{ __html: html }}
                    />
                </main>

                <footer className="border-t border-border/80">
                    <div className="mx-auto flex w-full max-w-3xl justify-center px-5 py-6 md:px-8">
                        <LegalFooterLinks />
                    </div>
                </footer>
            </div>
        </>
    );
}
