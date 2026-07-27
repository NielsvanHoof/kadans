import { Form, usePage } from '@inertiajs/react';
import { useTranslation } from '@/hooks/use-translation';
import { destroy } from '@/routes/platform/support';

/**
 * Persistent banner while a platform support session is active.
 */
export default function SupportSessionBanner() {
    const { t } = useTranslation();
    const supportSession = usePage().props.support_session;

    if (!supportSession) {
        return null;
    }

    return (
        <div
            role="status"
            className="flex flex-wrap items-center justify-between gap-3 border-b border-amber-600/30 bg-amber-50 px-4 py-2 text-sm text-amber-950 dark:border-amber-400/20 dark:bg-amber-950/40 dark:text-amber-50"
        >
            <p>
                {t('Support mode')}: {supportSession.tenant.name} —{' '}
                {supportSession.reason}
            </p>
            <Form
                {...destroy.form()}
                options={{ preserveScroll: true }}
                className="shrink-0"
            >
                {({ processing }) => (
                    <button
                        type="submit"
                        disabled={processing}
                        className="font-medium underline underline-offset-2 hover:no-underline disabled:opacity-50"
                    >
                        {t('Exit support')}
                    </button>
                )}
            </Form>
        </div>
    );
}
