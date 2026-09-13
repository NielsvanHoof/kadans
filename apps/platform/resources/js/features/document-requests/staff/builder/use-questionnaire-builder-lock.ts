import { router, useHttp } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import DossierBuilderLockController from '@/actions/App/Dossiers/Http/Controllers/Builder/DossierBuilderLockController';
import type { DossierBuilderLock } from '@/features/dossiers/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';

/** Matches App\Dossiers\Support\Builder\DossierBuilderLock::HEARTBEAT_INTERVAL_SECONDS. */
const HEARTBEAT_INTERVAL_MS = 60_000;

/** Matches App\Dossiers\Support\Builder\DossierBuilderLock::POLL_INTERVAL_SECONDS. */
const POLL_INTERVAL_MS = 30_000;

type LockResponse = {
    builder_lock: DossierBuilderLock;
};

/**
 * Read the encrypted XSRF cookie Laravel sets for SPA-style POSTs.
 */
function readXsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match?.[1] ? decodeURIComponent(match[1]) : null;
}

/**
 * Fire-and-forget POST that survives tab close (keepalive).
 */
function postKeepalive(url: string, body: Record<string, string>): void {
    const xsrf = readXsrfToken();

    void fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
        },
        body: JSON.stringify(body),
        credentials: 'same-origin',
        keepalive: true,
    });
}

/**
 * Keep / release the exclusive builder lock while the page is open.
 *
 * Call `releaseLock` from leave handlers so a cancelled dirty-leave dialog
 * does not drop the lease.
 */
export function useQuestionnaireBuilderLock({
    dossierId,
    initialLock,
    enabled,
}: {
    dossierId: number;
    initialLock: DossierBuilderLock;
    /** False for completed dossiers or read-only members. */
    enabled: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const [lock, setLock] = useState(initialLock);
    const lockRef = useRef(lock);
    const releasedRef = useRef(false);

    const routeArgs = useMemo(
        () => ({
            tenant: currentWorkspace,
            dossier: dossierId,
        }),
        [currentWorkspace, dossierId],
    );

    useEffect(() => {
        setLock(initialLock);
        lockRef.current = initialLock;
        releasedRef.current = false;
    }, [initialLock]);

    useEffect(() => {
        lockRef.current = lock;
    }, [lock]);

    const { post: heartbeatPost, setData: setHeartbeatData } = useHttp({
        token: '',
    });
    const { post: acquirePost } = useHttp({});

    const releaseLock = useCallback(() => {
        const current = lockRef.current;

        if (releasedRef.current || !current.is_mine || !current.token) {
            return;
        }

        releasedRef.current = true;
        postKeepalive(DossierBuilderLockController.destroy.url(routeArgs), {
            token: current.token,
        });
        lockRef.current = {
            ...current,
            is_mine: false,
            token: null,
        };
    }, [routeArgs]);

    // Heartbeat while we hold the lock.
    useEffect(() => {
        if (!enabled || !lock.is_mine || !lock.token) {
            return;
        }

        const token = lock.token;

        const beat = async () => {
            setHeartbeatData({ token });

            try {
                const response = (await heartbeatPost(
                    DossierBuilderLockController.heartbeat.url(routeArgs),
                )) as LockResponse | undefined;

                if (response?.builder_lock) {
                    setLock(response.builder_lock);
                }
            } catch {
                // Lost the lease — flip to read-only and reload server props.
                setLock((previous) => ({
                    ...previous,
                    is_mine: false,
                    token: null,
                }));
                router.flash({
                    toast: {
                        type: 'error',
                        message: t('This form is no longer locked by you.'),
                    },
                });
                router.reload({
                    only: ['builder_lock', 'dossier', 'can_manage'],
                });
            }
        };

        const intervalId = window.setInterval(() => {
            void beat();
        }, HEARTBEAT_INTERVAL_MS);

        return () => {
            window.clearInterval(intervalId);
        };
    }, [
        enabled,
        lock.is_mine,
        lock.token,
        heartbeatPost,
        setHeartbeatData,
        routeArgs,
        t,
    ]);

    // Poll acquire when another user holds the lock (or after expiry).
    useEffect(() => {
        if (!enabled || lock.is_mine) {
            return;
        }

        const poll = async () => {
            try {
                const response = (await acquirePost(
                    DossierBuilderLockController.store.url(routeArgs),
                )) as LockResponse | undefined;

                if (response?.builder_lock?.is_mine) {
                    router.reload({
                        only: ['builder_lock', 'dossier', 'can_manage'],
                    });
                } else if (response?.builder_lock) {
                    setLock(response.builder_lock);
                }
            } catch {
                // Ignore — next poll retries.
            }
        };

        const intervalId = window.setInterval(() => {
            void poll();
        }, POLL_INTERVAL_MS);

        return () => {
            window.clearInterval(intervalId);
        };
    }, [enabled, lock.is_mine, acquirePost, routeArgs]);

    // Tab close / refresh — release without waiting for React unmount.
    useEffect(() => {
        if (!enabled) {
            return;
        }

        const handlePageHide = () => {
            releaseLock();
        };

        window.addEventListener('pagehide', handlePageHide);

        return () => {
            window.removeEventListener('pagehide', handlePageHide);
        };
    }, [enabled, releaseLock]);

    return {
        lock,
        canEdit: enabled && lock.is_mine,
        releaseLock,
    };
}
