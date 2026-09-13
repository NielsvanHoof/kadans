import { useTranslation } from '@/hooks/use-translation';

/**
 * Machine error_code and raw error_message for the diagnostics sidebar.
 *
 * Deliberately omits the localised sentence — that lives in the recovery
 * banner so staff guidance and technical detail stay separate.
 */
export default function PipelineTechnicalError({
    errorCode,
    errorMessage,
}: {
    errorCode: string | null;
    errorMessage: string | null;
}) {
    const { t } = useTranslation();

    if (errorCode === null && errorMessage === null) {
        return null;
    }

    return (
        <div className="border-border bg-muted/40 space-y-1 rounded-md border px-3 py-2 text-sm">
            {errorCode !== null ? (
                <div>
                    <p className="text-muted-foreground">{t('Error code')}</p>
                    <p className="font-mono text-xs break-all">{errorCode}</p>
                </div>
            ) : null}
            {errorMessage !== null ? (
                <div>
                    <p className="text-muted-foreground">
                        {t('Error message')}
                    </p>
                    <p className="font-mono text-xs break-words">
                        {errorMessage}
                    </p>
                </div>
            ) : null}
        </div>
    );
}
