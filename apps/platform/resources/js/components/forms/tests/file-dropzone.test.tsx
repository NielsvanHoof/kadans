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

const {
    default: FileDropzone,
    DEFAULT_FILE_ACCEPT,
    DEFAULT_MAX_FILE_BYTES,
} = await import('@/components/forms/file-dropzone');

describe('FileDropzone', () => {
    it('wires the browser accept list for document types', () => {
        render(
            <FileDropzone
                inputId="document"
                label="Document"
                file={null}
                onFileChange={vi.fn()}
            />,
        );

        const input = document.getElementById('document') as HTMLInputElement;

        expect(input).toHaveAttribute('accept', DEFAULT_FILE_ACCEPT);
        expect(DEFAULT_FILE_ACCEPT).toContain('.pdf');
        expect(DEFAULT_FILE_ACCEPT).not.toContain('.exe');
    });

    it('accepts an in-limit file via the file input', async () => {
        const user = userEvent.setup();
        const onFileChange = vi.fn();

        render(
            <FileDropzone
                inputId="document"
                label="Document"
                file={null}
                onFileChange={onFileChange}
                maxBytes={DEFAULT_MAX_FILE_BYTES}
            />,
        );

        const input = document.getElementById('document') as HTMLInputElement;
        const file = new File(['pdf-bytes'], 'id.pdf', {
            type: 'application/pdf',
        });

        await user.upload(input, file);

        expect(onFileChange).toHaveBeenCalledWith(file);
        expect(screen.queryByText(/File is too large/)).not.toBeInTheDocument();
    });

    it('rejects an oversized file and clears the selection', async () => {
        const user = userEvent.setup();
        const onFileChange = vi.fn();
        const maxBytes = 100;

        render(
            <FileDropzone
                inputId="document"
                label="Document"
                file={null}
                onFileChange={onFileChange}
                maxBytes={maxBytes}
            />,
        );

        const input = document.getElementById('document') as HTMLInputElement;
        const file = new File([new ArrayBuffer(101)], 'big.pdf', {
            type: 'application/pdf',
        });

        await user.upload(input, file);

        expect(onFileChange).toHaveBeenCalledWith(null);
        expect(screen.getByRole('alert')).toHaveTextContent(
            /File is too large\. Maximum size is 100/,
        );
    });
});
