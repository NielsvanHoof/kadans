import { describe, expect, it } from 'vitest';
import {
    pipelineIsInFlight,
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
            'OCR document',
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
