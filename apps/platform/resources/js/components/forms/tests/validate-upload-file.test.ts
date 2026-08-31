import { describe, expect, it } from 'vite-plus/test';
import {
    fileTypeLabel,
    validateFileSize,
} from '@/components/forms/validate-upload-file';

/** Match the real translator's :token replacement so assertions stay readable. */
function t(
    key: string,
    replacements?: Record<string, string | number>,
): string {
    if (!replacements) {
        return key;
    }

    return Object.entries(replacements).reduce(
        (result, [token, value]) => result.replace(`:${token}`, String(value)),
        key,
    );
}

function makeFile(
    name: string,
    sizeBytes: number,
    type = 'application/pdf',
): File {
    return new File([new ArrayBuffer(sizeBytes)], name, { type });
}

describe('validateFileSize', () => {
    it('accepts a file at or under the limit', () => {
        const maxBytes = 1024;

        expect(
            validateFileSize(makeFile('ok.pdf', 1024), maxBytes, t),
        ).toBeNull();
        expect(validateFileSize(makeFile('ok.pdf', 1), maxBytes, t)).toBeNull();
        expect(
            validateFileSize(makeFile('empty.pdf', 0), maxBytes, t),
        ).toBeNull();
    });

    it('rejects an oversized file with the max size in the message', () => {
        const maxBytes = 1024;
        const message = validateFileSize(
            makeFile('big.pdf', 1025),
            maxBytes,
            t,
        );

        expect(message).toMatch(
            /File is too large\. Maximum size is 1\.0\s*KB\./,
        );
    });
});

describe('fileTypeLabel', () => {
    it('uppercases short extensions for accepted document types', () => {
        expect(fileTypeLabel(makeFile('contract.pdf', 10))).toBe('PDF');
        expect(fileTypeLabel(makeFile('photo.jpeg', 10, 'image/jpeg'))).toBe(
            'JPEG',
        );
        expect(fileTypeLabel(makeFile('sheet.xlsx', 10))).toBe('XLSX');
    });

    it('falls back to FILE when the extension is missing or too long', () => {
        expect(fileTypeLabel(makeFile('README', 10))).toBe('FILE');
        // Extension longer than 5 chars is treated as unknown.
        expect(fileTypeLabel(makeFile('archive.backup', 10))).toBe('FILE');
    });
});
