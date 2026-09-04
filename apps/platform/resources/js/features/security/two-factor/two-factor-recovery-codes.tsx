import { Form } from '@inertiajs/react';
import {
    Copy,
    Download,
    Eye,
    EyeOff,
    LockKeyhole,
    RefreshCw,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import AlertError from '@/components/primitives/alert-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useTranslation } from '@/hooks/use-translation';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

const RECOVERY_CODE_SKELETONS = [
    'recovery-code-skeleton-1',
    'recovery-code-skeleton-2',
    'recovery-code-skeleton-3',
    'recovery-code-skeleton-4',
    'recovery-code-skeleton-5',
    'recovery-code-skeleton-6',
    'recovery-code-skeleton-7',
    'recovery-code-skeleton-8',
] as const;

type TwoFactorRecoveryCodesProps = {
    recoveryCodesList: string[];
    fetchRecoveryCodes: () => Promise<void>;
    errors: string[];
};

export default function TwoFactorRecoveryCodes({
    recoveryCodesList,
    fetchRecoveryCodes,
    errors,
}: TwoFactorRecoveryCodesProps) {
    const { t } = useTranslation();
    const [codesAreVisible, setCodesAreVisible] = useState<boolean>(false);
    const codesSectionRef = useRef<HTMLUListElement | null>(null);
    const scrollTimeoutRef = useRef<number | null>(null);
    const canRegenerateCodes = recoveryCodesList.length > 0 && codesAreVisible;

    useEffect(() => {
        return () => {
            if (scrollTimeoutRef.current !== null) {
                window.clearTimeout(scrollTimeoutRef.current);
            }
        };
    }, []);

    const toggleCodesVisibility = useCallback(async () => {
        if (!codesAreVisible && !recoveryCodesList.length) {
            await fetchRecoveryCodes();
        }

        setCodesAreVisible(!codesAreVisible);

        if (!codesAreVisible) {
            if (scrollTimeoutRef.current !== null) {
                window.clearTimeout(scrollTimeoutRef.current);
            }

            scrollTimeoutRef.current = window.setTimeout(() => {
                codesSectionRef.current?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',
                });
                scrollTimeoutRef.current = null;
            });
        }
    }, [codesAreVisible, recoveryCodesList.length, fetchRecoveryCodes]);

    useEffect(() => {
        if (!recoveryCodesList.length) {
            void fetchRecoveryCodes();
        }
    }, [recoveryCodesList.length, fetchRecoveryCodes]);

    const RecoveryCodeIconComponent = codesAreVisible ? EyeOff : Eye;

    const copyAllCodes = useCallback(async () => {
        if (recoveryCodesList.length === 0) {
            return;
        }

        await navigator.clipboard.writeText(recoveryCodesList.join('\n'));
    }, [recoveryCodesList]);

    const downloadCodes = useCallback(() => {
        if (recoveryCodesList.length === 0) {
            return;
        }

        const blob = new Blob([recoveryCodesList.join('\n')], {
            type: 'text/plain;charset=utf-8',
        });
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = 'recovery-codes.txt';
        anchor.click();
        URL.revokeObjectURL(url);
    }, [recoveryCodesList]);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex gap-3">
                    <LockKeyhole className="size-4" aria-hidden="true" />
                    {t('2FA recovery codes')}
                </CardTitle>
                <CardDescription>
                    {t(
                        'Recovery codes let you regain access if you lose your 2FA device. Store them in a secure password manager.',
                    )}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="flex flex-col gap-3 select-none sm:flex-row sm:items-center sm:justify-between">
                    <Button
                        onClick={toggleCodesVisibility}
                        className="w-fit"
                        aria-expanded={codesAreVisible}
                        aria-controls="recovery-codes-section"
                    >
                        <RecoveryCodeIconComponent
                            className="size-4"
                            aria-hidden="true"
                        />
                        {codesAreVisible
                            ? t('Hide recovery codes')
                            : t('View recovery codes')}
                    </Button>

                    {canRegenerateCodes && (
                        <Form
                            {...regenerateRecoveryCodes.form()}
                            options={{ preserveScroll: true }}
                            onSuccess={fetchRecoveryCodes}
                        >
                            {({ processing }) => (
                                <Button
                                    variant="secondary"
                                    type="submit"
                                    isDisabled={processing}
                                    aria-describedby="regenerate-warning"
                                >
                                    <RefreshCw /> {t('Regenerate codes')}
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
                <div
                    id="recovery-codes-section"
                    className={`relative overflow-hidden transition-[height,opacity] duration-300 ${codesAreVisible ? 'h-auto opacity-100' : 'h-0 opacity-0'}`}
                    aria-hidden={!codesAreVisible}
                >
                    <div className="mt-3 space-y-3">
                        {errors?.length ? (
                            <AlertError errors={errors} />
                        ) : (
                            <>
                                <ul
                                    ref={codesSectionRef}
                                    className="bg-muted grid list-none gap-1 rounded-lg p-4 font-mono text-sm"
                                    aria-label={t('Recovery codes')}
                                >
                                    {recoveryCodesList.length ? (
                                        recoveryCodesList.map((code) => (
                                            <li
                                                key={code}
                                                className="select-text"
                                            >
                                                {code}
                                            </li>
                                        ))
                                    ) : (
                                        <li
                                            className="list-none space-y-2"
                                            aria-busy="true"
                                        >
                                            {RECOVERY_CODE_SKELETONS.map(
                                                (skeletonId) => (
                                                    <Skeleton
                                                        key={skeletonId}
                                                        className="h-4"
                                                        aria-hidden="true"
                                                    />
                                                ),
                                            )}
                                        </li>
                                    )}
                                </ul>

                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => void copyAllCodes()}
                                    >
                                        <Copy aria-hidden />
                                        {t('Copy all')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={downloadCodes}
                                    >
                                        <Download aria-hidden />
                                        {t('Download .txt')}
                                    </Button>
                                </div>

                                <div className="text-muted-foreground text-xs select-none">
                                    <p id="regenerate-warning">
                                        {t(
                                            'Each recovery code can be used once to access your account and will be removed after use. If you need more, click :action above.',
                                            {
                                                action: t('Regenerate codes'),
                                            },
                                        )}
                                    </p>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
