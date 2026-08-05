import { describe, expect, it } from 'vitest';
import {
    dossierStageCompletion,
    dossierStageHint,
    dossierStageLabel,
    STAGE_HINTS,
    STAGE_LABELS,
    STAGE_ORDER,
} from '@/features/dossiers/stage/dossier-stage';
import type { Dossier } from '@/features/dossiers/types';

const identity = (key: string) => key;

function dossier(overrides: Partial<Dossier> = {}): Dossier {
    return {
        document_requests: [],
        access_grants: [],
        status: 'draft',
        ...overrides,
    } as Dossier;
}

describe('dossierStageLabel', () => {
    it.each(STAGE_ORDER)('resolves the label for %s through t()', (stage) => {
        expect(dossierStageLabel(stage, identity)).toBe(STAGE_LABELS[stage]);
    });
});

describe('dossierStageHint', () => {
    it.each(STAGE_ORDER)('resolves the hint for %s through t()', (stage) => {
        expect(dossierStageHint(stage, identity)).toBe(STAGE_HINTS[stage]);
    });
});

describe('dossierStageCompletion', () => {
    it('marks every stage incomplete for a fresh draft', () => {
        const completion = dossierStageCompletion(dossier());

        expect(completion).toEqual({
            prepare: 'incomplete',
            invite: 'incomplete',
            review: 'incomplete',
        });
    });

    it('completes prepare once a questionnaire item exists', () => {
        const completion = dossierStageCompletion(
            dossier({
                document_requests: [{ id: 1 }] as Dossier['document_requests'],
            }),
        );

        expect(completion.prepare).toBe('completed');
    });

    it('completes invite once a valid access grant exists', () => {
        const completion = dossierStageCompletion(
            dossier({
                access_grants: [{ is_valid: true }] as Dossier['access_grants'],
            }),
        );

        expect(completion.invite).toBe('completed');
    });

    it('does not complete invite for an access grant that is no longer valid', () => {
        const completion = dossierStageCompletion(
            dossier({
                access_grants: [
                    { is_valid: false },
                ] as Dossier['access_grants'],
            }),
        );

        expect(completion.invite).toBe('incomplete');
    });

    it.each(['in_review', 'completed'] as const)(
        'completes invite once the dossier reaches %s, even without an access grant',
        (status) => {
            const completion = dossierStageCompletion(dossier({ status }));

            expect(completion.invite).toBe('completed');
        },
    );

    it('only completes review once the dossier is completed', () => {
        expect(
            dossierStageCompletion(dossier({ status: 'in_review' })).review,
        ).toBe('incomplete');
        expect(
            dossierStageCompletion(dossier({ status: 'completed' })).review,
        ).toBe('completed');
    });
});
