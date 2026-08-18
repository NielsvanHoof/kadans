import { describe, expect, it } from 'vitest';
import {
    DOSSIER_STATUS_LABELS,
    dossierStatusLabel,
} from '@/features/dossiers/status/dossier-status';
import type { DossierStatus } from '@/features/dossiers/types';

const identity = (key: string) => key;

describe('dossierStatusLabel', () => {
    it.each(Object.keys(DOSSIER_STATUS_LABELS) as DossierStatus[])(
        'resolves the label for %s through t()',
        (status) => {
            expect(dossierStatusLabel(status, identity)).toBe(
                DOSSIER_STATUS_LABELS[status],
            );
        },
    );
});
