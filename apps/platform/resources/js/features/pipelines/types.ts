export type PipelineStatus =
    'pending' | 'running' | 'completed' | 'failed' | 'stuck' | 'cancelled';

export type PipelineStepStatus =
    'pending' | 'running' | 'completed' | 'failed' | 'stuck' | 'skipped';

export type PipelineIndexRow = {
    id: number;
    type: string;
    status: PipelineStatus;
    current_stage: string | null;
    attempt_number: number;
    last_activity_at: string | null;
    created_at: string;
    subject_type: string;
    subject_id: number | null;
};

export type PipelineSubject = {
    type: string;
    id: number;
    label: string | null;
    context: string | null;
    href: string | null;
};

export type PipelineStepDetail = {
    key: string;
    status: PipelineStepStatus;
    attempts: number;
    started_at: string | null;
    last_activity_at: string | null;
    completed_at: string | null;
    error_code: string | null;
    error_message: string | null;
};

export type PipelineDetail = {
    id: number;
    type: string;
    status: PipelineStatus;
    current_stage: string | null;
    provider: string | null;
    provider_job_id: string | null;
    attempt_number: number;
    error_code: string | null;
    error_message: string | null;
    metadata: Record<string, unknown> | null;
    started_at: string | null;
    last_activity_at: string | null;
    next_retry_at: string | null;
    completed_at: string | null;
    failed_at: string | null;
    steps: PipelineStepDetail[];
    subject: PipelineSubject | null;
};
