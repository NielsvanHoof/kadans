import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { mockUseTranslation } from '@/test/mocks';

vi.mock('@/hooks/use-translation', () => mockUseTranslation());

const {
    ConfidenceBadge,
    formatFieldValue,
    formatRawJson,
    maskFieldValue,
    sensitivityLabel,
} =
    await import('@/features/document-requests/staff/review/document-extraction-helpers');

describe('formatFieldValue', () => {
    it('joins array values for display', () => {
        expect(formatFieldValue(['a', 'b'])).toBe('a, b');
    });

    it('returns scalar values unchanged', () => {
        expect(formatFieldValue('plain')).toBe('plain');
    });
});

describe('maskFieldValue', () => {
    it('masks scalars without revealing length beyond a safe cap', () => {
        expect(maskFieldValue('12345678901234567890')).toBe('••••••••••••');
    });

    it('masks each array entry with a fixed-width mask', () => {
        expect(maskFieldValue(['12', '3456789'])).toBe('••••••••, ••••••••');
    });
});

describe('sensitivityLabel', () => {
    const identity = (key: string) => key;

    it('maps known PII categories', () => {
        expect(sensitivityLabel('iban', identity)).toBe('IBAN');
        expect(sensitivityLabel('bsn', identity)).toBe('BSN');
    });

    it('falls back to Sensitive for unknown categories', () => {
        expect(sensitivityLabel(null, identity)).toBe('Sensitive');
    });
});

describe('formatRawJson', () => {
    it('pretty-prints valid JSON', () => {
        expect(formatRawJson('{"a":1}')).toBe('{\n  "a": 1\n}');
    });

    it('returns the original string when JSON is invalid', () => {
        expect(formatRawJson('not-json')).toBe('not-json');
    });
});

describe('ConfidenceBadge', () => {
    it('uses destructive styling below 80%', () => {
        render(<ConfidenceBadge confidence={0.75} />);

        expect(
            screen.getByText('75%').closest('[data-variant]'),
        ).toHaveAttribute('data-variant', 'destructive');
    });

    it('uses outline styling between 80% and 90%', () => {
        render(<ConfidenceBadge confidence={0.85} />);

        expect(
            screen.getByText('85%').closest('[data-variant]'),
        ).toHaveAttribute('data-variant', 'outline');
    });

    it('uses secondary styling at 90% and above', () => {
        render(<ConfidenceBadge confidence={0.95} />);

        expect(
            screen.getByText('95%').closest('[data-variant]'),
        ).toHaveAttribute('data-variant', 'secondary');
    });
});
