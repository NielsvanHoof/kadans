import { Head, Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { ShieldAlert, ShieldOff, TriangleAlert, Wrench } from 'lucide-react';
import AppLogoIcon from '@/components/app-shell/app-logo-icon';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard, login } from '@/routes';

type ErrorStatus = 403 | 404 | 500 | 503;

type ErrorCopy = {
    title: string;
    description: string;
    icon: LucideIcon;
};

type Props = {
    status: ErrorStatus;
};

/**
 * Standalone HTTP error page (403/404/500/503).
 * No app sidebar — works for guests and authenticated users.
 */
export default function ErrorPage({ status }: Props) {
    const { t } = useTranslation();
    const { auth, name } = usePage().props;
    const isAuthenticated = auth.user !== null;

    const copyByStatus: Record<ErrorStatus, ErrorCopy> = {
        403: {
            title: t('Forbidden'),
            description: t('Sorry, you are not allowed to access this page.'),
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

            <div className="flex min-h-svh flex-col items-center justify-center bg-background px-6 py-16">
                <main
                    id="main-content"
                    tabIndex={-1}
                    className="flex w-full max-w-md flex-col items-center text-center"
                >
                    <Link
                        href={isAuthenticated ? dashboard() : login()}
                        aria-label={name}
                        className="mb-10 flex size-12 items-center justify-center rounded-md bg-primary text-primary-foreground"
                    >
                        <AppLogoIcon className="size-7 text-primary-foreground" />
                    </Link>

                    <p className="mb-3 font-mono text-sm tracking-widest text-muted-foreground uppercase">
                        {status}
                    </p>

                    <div className="mb-4 flex size-14 items-center justify-center rounded-2xl bg-muted">
                        <Icon
                            className="size-7 text-muted-foreground"
                            aria-hidden
                        />
                    </div>

                    <h1 className="font-display text-2xl font-semibold tracking-tight text-balance">
                        {copy.title}
                    </h1>
                    <p className="mt-2 text-sm text-pretty text-muted-foreground">
                        {copy.description}
                    </p>

                    <Button asChild className="mt-8">
                        <Link href={isAuthenticated ? dashboard() : login()}>
                            {isAuthenticated
                                ? t('Back to dashboard')
                                : t('Log in')}
                        </Link>
                    </Button>
                </main>
            </div>
        </>
    );
}
