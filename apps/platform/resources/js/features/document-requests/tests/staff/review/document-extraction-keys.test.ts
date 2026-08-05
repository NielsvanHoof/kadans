import { describe, expect, it } from 'vitest';
import {
    keyedCells,
    keyedFieldsList,
    keyedRows,
    keyedStrings,
    keyedTablesList,
} from '@/features/document-requests/staff/review/document-extraction-keys';
import type {
    DocumentExtractionField,
    DocumentExtractionTable,
} from '@/features/document-requests/types';

describe('keyedStrings', () => {
    it('assigns occurrence-based keys when values repeat', () => {
        expect(keyedStrings(['a', 'b', 'a'])).toEqual([
            { value: 'a', key: 'a#1' },
            { value: 'b', key: 'b#1' },
            { value: 'a', key: 'a#2' },
        ]);
    });
});

describe('keyedCells', () => {
    it('maps row cells to stable keys', () => {
        expect(keyedCells(['EUR', 'EUR'])).toEqual([
            { cell: 'EUR', key: 'EUR#1' },
            { cell: 'EUR', key: 'EUR#2' },
        ]);
    });
});

describe('keyedFieldsList', () => {
    it('dedupes fields with the same label and serialized value', () => {
        const fields: DocumentExtractionField[] = [
            {
                label: 'IBAN',
                value: 'NL00TEST',
                confidence: null,
                sensitivity: 'iban',
            },
            {
                label: 'IBAN',
                value: 'NL00TEST',
                confidence: null,
                sensitivity: 'iban',
            },
            {
                label: 'Name',
                value: 'Ada',
                confidence: null,
                sensitivity: null,
            },
        ];

        const keys = keyedFieldsList(fields).map((item) => item.key);

        expect(keys[0]).toBe('IBAN:NL00TEST#1');
        expect(keys[1]).toBe('IBAN:NL00TEST#2');
        expect(keys[2]).toBe('Name:Ada#1');
    });

    it('treats array values as comma-separated fingerprints', () => {
        const fields: DocumentExtractionField[] = [
            {
                label: 'Tags',
                value: ['a', 'b'],
                confidence: null,
                sensitivity: null,
            },
            {
                label: 'Tags',
                value: ['a', 'b'],
                confidence: null,
                sensitivity: null,
            },
        ];

        expect(keyedFieldsList(fields).map((item) => item.key)).toEqual([
            'Tags:a, b#1',
            'Tags:a, b#2',
        ]);
    });
});

describe('keyedTablesList', () => {
    it('keys tables by full shape fingerprint', () => {
        const table: DocumentExtractionTable = {
            title: 'Lines',
            headers: ['Description', 'Amount'],
            rows: [['Rent', '1000']],
        };

        const [first, second] = keyedTablesList([table, table]);

        expect(first.key).toContain('table:');
        expect(first.key.endsWith('#1')).toBe(true);
        expect(second.key.endsWith('#2')).toBe(true);
    });
});

describe('keyedRows', () => {
    it('keys duplicate rows independently', () => {
        const row = ['Rent', '1000'];
        const [first, second] = keyedRows([row, row]);

        expect(first.key).toBe(`${JSON.stringify(row)}#1`);
        expect(second.key).toBe(`${JSON.stringify(row)}#2`);
    });
});
