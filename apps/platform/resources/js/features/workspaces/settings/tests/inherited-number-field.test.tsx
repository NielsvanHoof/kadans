import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const { default: InheritedNumberField } =
    await import('@/features/workspaces/settings/inherited-number-field');

function setting(
    overrides: Partial<App.Tenancy.Data.FirmSettingData> = {},
): App.Tenancy.Data.FirmSettingData {
    return {
        override: null,
        effective: 15,
        platform_default: 15,
        min: 1,
        max: 15,
        ...overrides,
    };
}

describe('InheritedNumberField', () => {
    it('leaves the input empty and offers the standard value when the firm has set nothing', () => {
        render(
            <InheritedNumberField
                name="portal_session_idle_minutes"
                label="Sign out after inactivity"
                description="Minutes without activity."
                unit="minutes"
                setting={setting()}
            />,
        );

        const input = screen.getByLabelText(
            'Sign out after inactivity',
        ) as HTMLInputElement;

        expect(input.value).toBe('');
        expect(input.placeholder).toBe('15');
    });

    it('shows the firm’s own value once it has set one', () => {
        render(
            <InheritedNumberField
                name="portal_session_idle_minutes"
                label="Sign out after inactivity"
                description="Minutes without activity."
                unit="minutes"
                setting={setting({ override: 5, effective: 5 })}
            />,
        );

        expect(
            (
                screen.getByLabelText(
                    'Sign out after inactivity',
                ) as HTMLInputElement
            ).value,
        ).toBe('5');
    });

    it('carries the server bounds onto the input so the browser agrees with the validator', () => {
        render(
            <InheritedNumberField
                name="retention_archived_days"
                label="Archived clients and dossiers"
                description="Days kept."
                unit="days"
                setting={setting({
                    effective: 1095,
                    platform_default: 1095,
                    min: 365,
                    max: 3650,
                })}
            />,
        );

        const input = screen.getByLabelText(
            'Archived clients and dossiers',
        ) as HTMLInputElement;

        expect(input.min).toBe('365');
        expect(input.max).toBe('3650');
    });

    it('says there is no default when the setting has none', () => {
        render(
            <InheritedNumberField
                name="default_reminder_interval_days"
                label="Default reminder interval"
                description="Days between reminders."
                unit="days"
                setting={setting({
                    effective: null,
                    platform_default: null,
                    max: 30,
                })}
            />,
        );

        expect(
            screen.getByText(/Leave empty for no default/),
        ).toBeInTheDocument();
    });
});
