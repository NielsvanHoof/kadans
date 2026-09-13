import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

const { default: DossierFollowUpFields } =
    await import('@/features/dossiers/follow-up/dossier-follow-up-fields');

function renderFields(defaultReminderIntervalDays: number | null) {
    return render(
        <DossierFollowUpFields
            responsibleStaff={[]}
            defaultReminderIntervalDays={defaultReminderIntervalDays}
            errors={{}}
        />,
    );
}

function intervalInput(): HTMLInputElement {
    return screen.getByLabelText('Automatic reminder cadence');
}

/**
 * These fields are shared by the create and the edit form, and the two must not
 * be handed the same default. Create passes the firm's house rhythm; edit
 * passes the dossier's own interval, which is null when someone deliberately
 * turned reminders off. Passing the firm default on edit would switch them
 * back on for exactly those dossiers.
 */
describe('DossierFollowUpFields reminder interval', () => {
    it("prefills the firm's rhythm when one is given", () => {
        renderFields(7);

        expect(intervalInput().value).toBe('7');
    });

    it('stays empty when nothing is given, so reminders stay off', () => {
        renderFields(null);

        expect(intervalInput().value).toBe('');
    });
});
