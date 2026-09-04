import { Head, Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { ShieldAlert, ShieldOff, TriangleAlert, Wrench } from 'lucide-react';
import AppLogoIcon from '@/components/app-shell/app-logo-icon';
import { LinkButton } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { errorPageHomeRoute } from '@/lib/error-page-home-route';

type ErrorStatus = 403 | 404 | 500 | 503;

type ErrorCopy = {
    title: string;
    description: string;
    icon: LucideIcon;
};

type ErrorPageProps = {
    status: ErrorStatus;
};

export default function ErrorPage({ status }: ErrorPageProps) {
    const { t } = useTranslation();
    const { auth, name } = usePage().props;
    const isAuthenticated = auth.user !== null;
    // One destination for logo + CTA — avoids the inverted-ternary drift.
    const home = errorPageHomeRoute(isAuthenticated);

    const copyByStatus: Record<ErrorStatus, ErrorCopy> = {
        403: {
            title: t('Forbidden'),
            description: t("You don't have permission to view this page."),
            icon: ShieldOff,
        },
        404: {
            title: t('Page not found'),
            description: t(
                'Sorry, the page you are looking for could not be found.',
            ),
            icon: ShieldAlert,
        },
        500: {
            title: t('Server error'),
            description: t('Whoops, something went wrong on our servers.'),
            icon: TriangleAlert,
        },
        503: {
            title: t('Service unavailable'),
            description: t(
                'Sorry, we are doing some maintenance. Please check back soon.',
            ),
            icon: Wrench,
        },
    };

    const copy = copyByStatus[status] ?? copyByStatus[500];
    const Icon = copy.icon;

    return (
        <>
            <Head title={copy.title} />

            <div className="bg-background flex min-h-svh flex-col items-center justify-center px-6 py-16">
                <main
                    id="main-content"
                    tabIndex={-1}
                    className="flex w-full max-w-md flex-col items-center text-center"
                >
                    <Link
                        href={home}
                        aria-label={name}
                        className="bg-primary text-primary-foreground mb-10 flex size-12 items-center justify-center rounded-md"
                    >
                        <AppLogoIcon className="text-primary-foreground size-7" />
                    </Link>

                    <p className="text-muted-foreground mb-3 font-mono text-sm tracking-widest uppercase">
                        {status}
                    </p>

                    <div className="bg-muted mb-4 flex size-14 items-center justify-center rounded-2xl">
                        <Icon
                            className="text-muted-foreground size-7"
                            aria-hidden
                        />
                    </div>

                    <h1 className="font-display text-2xl font-semibold tracking-tight text-balance">
                        {copy.title}
                    </h1>
                    <p className="text-muted-foreground mt-2 text-sm text-pretty">
                        {copy.description}
                    </p>

                    <LinkButton className="mt-8" href={home.url}>
                        {isAuthenticated
                            ? t('Go to dashboard')
                            : t('Go to login')}
                    </LinkButton>
                </main>
            </div>
        </>
    );
}
