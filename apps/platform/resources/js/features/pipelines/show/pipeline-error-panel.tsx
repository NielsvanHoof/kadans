import { pipelineErrorCodeLabel } from '@/features/pipelines/status/pipeline-status';
import { useTranslation } from '@/hooks/use-translation';

/**
 * The failure block shared by the run diagnostics card and the step timeline.
 *
 * Reads top-down as sentence, then evidence: the localised explanation derived
 * from `error_code` leads, and the machine code plus the stored `error_message`
 * follow in mono as the detail an engineer needs. An unrecognised code has no
 * sentence to lead with, so the raw message is promoted to that position
 * instead of leaving the panel wordless.
 */
export default function PipelineErrorPanel({
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

    const sentence =
        errorCode === null ? null : pipelineErrorCodeLabel(errorCode, t);
    const heading = sentence ?? errorMessage;
    const showsMessageAsDetail = sentence !== null && errorMessage !== null;

    return (
        <div className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm">
            {heading !== null ? (
                <p className="text-destructive">{heading}</p>
            ) : null}
            {errorCode !== null ? (
                <p className="mt-1 font-mono text-xs text-muted-foreground">
                    {errorCode}
                </p>
            ) : null}
            {showsMessageAsDetail ? (
                <p className="font-mono text-xs break-words text-muted-foreground">
                    {errorMessage}
                </p>
            ) : null}
        </div>
    );
}
