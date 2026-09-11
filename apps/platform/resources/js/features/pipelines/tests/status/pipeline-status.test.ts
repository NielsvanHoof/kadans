import { describe, expect, it } from 'vite-plus/test';
import {
    pipelineErrorCodeLabel,
    pipelineIsInFlight,
    pipelineNeedsLiveReload,
    pipelineRecoveryKind,
    pipelineStatusLabel,
    pipelineStepLabel,
    pipelineStepStatusLabel,
    pipelineStepStatusTone,
    pipelineTypeLabel,
} from '@/features/pipelines/status/pipeline-status';
import type {
    PipelineStatus,
    PipelineStepStatus,
} from '@/features/pipelines/types';

const identity = (key: string) => key;

describe('pipelineStatusLabel', () => {
    it.each([
        ['pending', 'Pending'],
        ['running', 'Running'],
        ['completed', 'Completed'],
        ['failed', 'Failed'],
        ['stuck', 'Stuck'],
        ['cancelled', 'Cancelled'],
    ] as [PipelineStatus, string][])('resolves %s', (status, label) => {
        expect(pipelineStatusLabel(status, identity)).toBe(label);
    });
});

describe('pipelineStepStatusLabel', () => {
    it.each([
        ['pending', 'Pending'],
        ['running', 'Running'],
        ['completed', 'Completed'],
        ['failed', 'Failed'],
        ['stuck', 'Stuck'],
        ['skipped', 'Skipped'],
    ] as [PipelineStepStatus, string][])('resolves %s', (status, label) => {
        expect(pipelineStepStatusLabel(status, identity)).toBe(label);
    });
});

describe('pipelineIsInFlight', () => {
    it('is true only while pending or running', () => {
        expect(pipelineIsInFlight('pending')).toBe(true);
        expect(pipelineIsInFlight('running')).toBe(true);
        expect(pipelineIsInFlight('completed')).toBe(false);
        expect(pipelineIsInFlight('failed')).toBe(false);
        expect(pipelineIsInFlight('stuck')).toBe(false);
        expect(pipelineIsInFlight('cancelled')).toBe(false);
    });
});

describe('pipelineNeedsLiveReload', () => {
    it('is true while in flight or while an automatic retry is booked', () => {
        expect(pipelineNeedsLiveReload('pending', null)).toBe(true);
        expect(pipelineNeedsLiveReload('running', null)).toBe(true);
        expect(
            pipelineNeedsLiveReload('failed', '2026-08-17T12:00:00+00:00'),
        ).toBe(true);
        expect(pipelineNeedsLiveReload('failed', null)).toBe(false);
        expect(pipelineNeedsLiveReload('completed', null)).toBe(false);
        expect(pipelineNeedsLiveReload('stuck', null)).toBe(false);
    });
});

describe('pipelineRecoveryKind', () => {
    it('is scheduled when a failed run still has a next retry', () => {
        expect(
            pipelineRecoveryKind('failed', '2026-08-17T12:00:00+00:00'),
        ).toBe('scheduled');
    });

    it('is staff_action when auto-retry is exhausted or the run is stuck or cancelled', () => {
        expect(pipelineRecoveryKind('failed', null)).toBe('staff_action');
        expect(pipelineRecoveryKind('stuck', null)).toBe('staff_action');
        expect(pipelineRecoveryKind('cancelled', null)).toBe('staff_action');
        expect(
            pipelineRecoveryKind('stuck', '2026-08-17T12:00:00+00:00'),
        ).toBe('staff_action');
    });

    it('is null while the run is pending, running, or completed', () => {
        expect(pipelineRecoveryKind('pending', null)).toBeNull();
        expect(pipelineRecoveryKind('running', null)).toBeNull();
        expect(pipelineRecoveryKind('completed', null)).toBeNull();
    });
});

describe('pipelineStepStatusTone', () => {
    it('uses destructive tones for failed and stuck steps', () => {
        expect(pipelineStepStatusTone('failed')).toContain('destructive');
        expect(pipelineStepStatusTone('stuck')).toContain('destructive');
    });

    it('highlights the running step with a ring', () => {
        expect(pipelineStepStatusTone('running')).toContain('ring-4');
    });
});

describe('pipelineTypeLabel', () => {
    it('resolves known pipeline types and falls back to the raw key', () => {
        expect(pipelineTypeLabel('ocr_document', identity)).toBe(
            'Document scan',
        );
        expect(pipelineTypeLabel('custom_job', identity)).toBe('custom_job');
    });
});

describe('pipelineStepLabel', () => {
    it('resolves known step keys and falls back to the raw key', () => {
        expect(pipelineStepLabel('transcribe', identity)).toBe('Transcribe');
        expect(pipelineStepLabel('unknown_step', identity)).toBe(
            'unknown_step',
        );
    });
});

describe('pipelineErrorCodeLabel', () => {
    it('resolves the monitor code every stuck run is marked with', () => {
        expect(pipelineErrorCodeLabel('pipeline_inactive', identity)).toBe(
            'This run recorded no activity within the monitoring window and was marked stuck.',
        );
    });

    it('resolves the per-status Textract codes the OCR path composes', () => {
        expect(pipelineErrorCodeLabel('textract_FAILED', identity)).toBe(
            "We couldn't read this document.",
        );
        expect(
            pipelineErrorCodeLabel('textract_PARTIAL_SUCCESS', identity),
        ).toBe('We could read only part of this document.');
    });

    it('returns null for an unknown code so callers can fall back to the raw message', () => {
        expect(pipelineErrorCodeLabel('textract_SOMETHING_NEW', identity)).toBe(
            null,
        );
    });
});
