import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mockUseFormatters, mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());
vi.mock('@/hooks/use-formatters', () => mockUseFormatters());

const { default: DossierFollowUpFields } =
    await import('@/features/dossiers/follow-up/dossier-follow-up-fields');

describe('DossierFollowUpFields', () => {
    let warnSpy: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        // React Aria useLabel warns when Select has no aria-label /
        // aria-labelledby — catch that so the create-dossier page stays quiet.
        warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        warnSpy.mockRestore();
    });

    it('names the responsible-staff Select so useLabel does not warn', () => {
        render(
            <DossierFollowUpFields
                responsibleStaff={[
                    {
                        id: 3,
                        name: 'Ada Lovelace',
                        email: 'ada@example.com',
                    },
                ]}
                errors={{}}
            />,
        );

        // SelectValue text is included in the accessible name alongside
        // aria-label ("Unassigned Responsible staff member").
        expect(
            screen.getByRole('button', {
                name: /Responsible staff member/,
            }),
        ).toHaveAttribute('aria-label', 'Responsible staff member');

        expect(warnSpy).not.toHaveBeenCalledWith(
            expect.stringContaining(
                'If you do not provide a visible label, you must specify an aria-label',
            ),
        );
    });
});
