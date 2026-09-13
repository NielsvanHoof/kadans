import { pipelineErrorCodeLabel } from '@/features/pipelines/status/pipeline-status';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Step-level failure evidence on the execution timeline.
 *
 * Reads top-down as sentence, then evidence: the localised explanation derived
 * from `error_code` leads, and the machine code plus the stored `error_message`
 * follow in mono. An unrecognised code has no sentence to lead with, so the
 * raw message is promoted to that position instead of leaving the panel
 * wordless. Run-level recovery guidance and technical diagnostics use their
 * own components so those three layers stay distinct.
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
        <div className="border-destructive/30 bg-destructive/5 rounded-md border px-3 py-2 text-sm">
            {heading !== null ? (
                <p className="text-destructive">{heading}</p>
            ) : null}
            {errorCode !== null ? (
                <p className="text-muted-foreground mt-1 font-mono text-xs">
                    {errorCode}
                </p>
            ) : null}
            {showsMessageAsDetail ? (
                <p className="text-muted-foreground font-mono text-xs break-words">
                    {errorMessage}
                </p>
            ) : null}
        </div>
    );
}
