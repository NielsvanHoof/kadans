import type {
    PipelineStatus,
    PipelineStepStatus,
} from '@/features/pipelines/types';

const PIPELINE_STATUS_LABELS: Record<PipelineStatus, string> = {
    pending: 'Pending',
    running: 'Running',
    completed: 'Completed',
    failed: 'Failed',
    stuck: 'Stuck',
    cancelled: 'Cancelled',
};

const PIPELINE_STEP_STATUS_LABELS: Record<PipelineStepStatus, string> = {
    pending: 'Pending',
    running: 'Running',
    completed: 'Completed',
    failed: 'Failed',
    stuck: 'Stuck',
    skipped: 'Skipped',
};

export function pipelineStatusLabel(
    status: PipelineStatus,
    t: (key: string) => string,
): string {
    return t(PIPELINE_STATUS_LABELS[status]);
}

export function pipelineStepStatusLabel(
    status: PipelineStepStatus,
    t: (key: string) => string,
): string {
    return t(PIPELINE_STEP_STATUS_LABELS[status]);
}

/** True while the run is still active and should be polled on the show page. */
export function pipelineIsInFlight(status: PipelineStatus): boolean {
    return status === 'pending' || status === 'running';
}

/**
 * Whether the show page should stay subscribed to pipeline.updated.
 *
 * In-flight runs need live updates as steps advance. A failed run with
 * next_retry_at booked also needs them — otherwise the "Retry scheduled"
 * banner stays stale until a manual refresh after the automatic retry fires.
 */
export function pipelineNeedsLiveReload(
    status: PipelineStatus,
    nextRetryAt: string | null,
): boolean {
    return pipelineIsInFlight(status) || nextRetryAt !== null;
}

export type PipelineRecoveryKind = 'scheduled' | 'staff_action' | null;

/**
 * Derive the recovery guidance kind from run status and next_retry_at.
 *
 * Scheduled: automatic backoff still has budget (failed + next_retry_at).
 * Staff action: failed past the cap, stuck (never auto-retries), or cancelled.
 * Null: nothing for staff to recover — pending, running, or completed.
 */
export function pipelineRecoveryKind(
    status: PipelineStatus,
    nextRetryAt: string | null,
): PipelineRecoveryKind {
    if (status === 'failed' && nextRetryAt !== null) {
        return 'scheduled';
    }

    if (status === 'failed' || status === 'stuck' || status === 'cancelled') {
        return 'staff_action';
    }

    return null;
}

export function pipelineStepStatusTone(status: PipelineStepStatus): string {
    switch (status) {
        case 'completed':
            return 'border-primary bg-primary text-primary-foreground';
        case 'failed':
        case 'stuck':
            return 'border-destructive bg-destructive text-destructive-foreground';
        case 'running':
            return 'border-foreground bg-background text-foreground ring-4 ring-muted';
        case 'skipped':
            return 'border-muted-foreground/40 bg-muted text-muted-foreground';
        default:
            return 'border-border bg-background text-muted-foreground';
    }
}

export function pipelineTypeLabel(
    type: string,
    t: (key: string) => string,
): string {
    const labels: Record<string, string> = {
        ocr_document: 'Document scan',
        dossier_export: 'Dossier export',
        audio_transcription: 'Audio transcription',
    };

    return t(labels[type] ?? type);
}

/**
 * Localised sentence for a pipeline or step error code.
 *
 * The stored `error_message` cannot be translated: it is either an exception
 * message or English prose written by a scheduled command, which has no user
 * locale to translate against. The `error_code` beside it is a stable machine
 * string, so the readable sentence is derived from the code here and the raw
 * message stays on screen as technical detail.
 *
 * Returns null for an unrecognised code so callers fall back to that message
 * rather than showing nothing.
 */
const PIPELINE_ERROR_CODE_LABELS: Record<string, string> = {
    pipeline_inactive:
        'This run recorded no activity within the monitoring window and was marked stuck.',
    result_processing_failed: 'Processing failed after several attempts.',
    ocr_job_failed: 'Reading this document failed.',
    dossier_export_failed: 'Building the dossier export failed.',
    transcribe_provider_failed: 'Transcription failed.',
    transcription_job_failed: 'Transcribing this recording failed.',
    textract_FAILED: "We couldn't read this document.",
    textract_PARTIAL_SUCCESS: 'We could read only part of this document.',
};

export function pipelineErrorCodeLabel(
    code: string,
    t: (key: string) => string,
): string | null {
    const label = PIPELINE_ERROR_CODE_LABELS[code];

    return label === undefined ? null : t(label);
}

export function pipelineStepLabel(
    key: string,
    t: (key: string) => string,
): string {
    const labels: Record<string, string> = {
        submit: 'Submit',
        provider_processing: 'Provider processing',
        process_results: 'Process results',
        build_archive: 'Build archive',
        store_archive: 'Store archive',
        transcribe: 'Transcribe',
    };

    return t(labels[key] ?? key);
}
