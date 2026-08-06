import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/hooks/use-translation', () => ({
    useTranslation: () => ({
        t: (key: string, replacements?: Record<string, string | number>) => {
            if (!replacements) {
                return key;
            }

            return Object.entries(replacements).reduce(
                (result, [token, value]) =>
                    result.replace(`:${token}`, String(value)),
                key,
            );
        },
        locale: 'en',
        availableLocales: [],
    }),
}));

const { default: MultiFileDropzone, DEFAULT_MAX_FILES } =
    await import('@/components/forms/multi-file-dropzone');

describe('MultiFileDropzone', () => {
    it('accumulates in-limit files and skips oversized ones', async () => {
        const user = userEvent.setup();
        const onFilesChange = vi.fn();
        const maxBytes = 100;

        render(
            <MultiFileDropzone
                inputId="documents"
                label="Documents"
                files={[]}
                onFilesChange={onFilesChange}
                maxBytes={maxBytes}
                maxFiles={DEFAULT_MAX_FILES}
            />,
        );

        const input = document.getElementById('documents') as HTMLInputElement;
        const ok = new File(['ok'], 'ok.pdf', { type: 'application/pdf' });
        const big = new File([new ArrayBuffer(101)], 'big.pdf', {
            type: 'application/pdf',
        });

        await user.upload(input, [ok, big]);

        expect(onFilesChange).toHaveBeenCalledWith([ok]);
        expect(screen.getByRole('alert')).toHaveTextContent(
            /File is too large\. Maximum size is 100/,
        );
    });

    it('rejects adding files when already at capacity', async () => {
        const user = userEvent.setup();
        const onFilesChange = vi.fn();
        const existing = new File(['a'], 'a.pdf', { type: 'application/pdf' });

        render(
            <MultiFileDropzone
                inputId="documents"
                label="Documents"
                files={[existing]}
                onFilesChange={onFilesChange}
                maxFiles={1}
            />,
        );

        const input = document.getElementById('documents') as HTMLInputElement;
        const next = new File(['b'], 'b.pdf', { type: 'application/pdf' });

        // Capacity is full — browse target is gone; upload still exercises addFiles.
        await user.upload(input, next);

        expect(onFilesChange).not.toHaveBeenCalled();
        expect(
            screen.getByText('You can add up to 1 files.'),
        ).toBeInTheDocument();
    });
});
