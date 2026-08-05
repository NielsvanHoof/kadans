import { describe, expect, it } from 'vitest';
import {
    QUESTIONNAIRE_ITEM_TYPES,
    questionnaireItemTypeCapabilities,
} from '@/features/document-requests/item-types/generated';
import {
    canDriveVisibility,
    storesAnswerOptionIds,
    storesAnswerText,
} from '@/features/document-requests/types';

/**
 * These helpers gate builder visibility rules and answer storage. They must stay
 * aligned with the PHP-generated capabilities map.
 */
describe('questionnaire item type capabilities', () => {
    it.each(QUESTIONNAIRE_ITEM_TYPES)('exposes capabilities for %s', (type) => {
        const caps = questionnaireItemTypeCapabilities[type];

        expect(storesAnswerText(type)).toBe(caps.storesAnswerText);
        expect(storesAnswerOptionIds(type)).toBe(caps.storesAnswerOptionIds);
        expect(canDriveVisibility(type)).toBe(caps.canDriveVisibility);
    });

    it('only choice-like types can drive visibility', () => {
        const drivers = QUESTIONNAIRE_ITEM_TYPES.filter(canDriveVisibility);

        expect(drivers).toEqual(['boolean', 'choice', 'multi_select']);
    });

    it('never stores both text and option ids for the same type', () => {
        for (const type of QUESTIONNAIRE_ITEM_TYPES) {
            expect(storesAnswerText(type) && storesAnswerOptionIds(type)).toBe(
                false,
            );
        }
    });
});
