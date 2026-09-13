<?php

declare(strict_types=1);

use App\DocumentProcessing\Services\Ocr\FlattenExtractedTextForSearch;

function bankStatementExtractionJson(): string
{
    return json_encode([
        'document_type' => 'bank_statement',
        'summary' => 'Monthly salary payments for March.',
        'fields' => [
            [
                'label' => 'IBAN',
                'value' => 'NL18 RABO 0123 4567 89',
                'confidence' => 0.99,
                'sensitivity' => 'iban',
            ],
            [
                'label' => 'BSN',
                'value' => '123456789',
                'confidence' => 0.91,
                'sensitivity' => 'bsn',
            ],
        ],
        'tables' => [
            [
                'title' => 'Transactions',
                'headers' => ['Date', 'Amount'],
                'rows' => [['2026-03-01', '2500']],
            ],
        ],
        'notes' => ['Salary credited on the first.'],
        'confidence' => 0.96,
    ], JSON_THROW_ON_ERROR);
}

covers(FlattenExtractedTextForSearch::class);

test('flatten includes filename fields tables and a compact iban variant', function (): void {
    $flatten = new FlattenExtractedTextForSearch;
    $text = $flatten->searchableText('statement.pdf', bankStatementExtractionJson());

    expect($text)->toContain('statement.pdf')
        ->and($text)->toContain('bank_statement')
        ->and($text)->toContain('Monthly salary payments for March.')
        ->and($text)->toContain('NL18 RABO 0123 4567 89')
        ->and($text)->toContain('NL18RABO0123456789')
        ->and($text)->toContain('123456789')
        ->and($text)->toContain('2500');
});

test('flatten caps searchable text length', function (): void {
    $flatten = new FlattenExtractedTextForSearch;
    $payload = json_encode([
        'document_type' => 'other',
        'summary' => str_repeat('a', FlattenExtractedTextForSearch::MAX_SEARCHABLE_LENGTH + 50),
        'fields' => [],
        'tables' => [],
        'notes' => [],
        'confidence' => null,
    ], JSON_THROW_ON_ERROR);

    $text = $flatten->searchableText('huge.pdf', $payload);

    expect($text)->toHaveLength(FlattenExtractedTextForSearch::MAX_SEARCHABLE_LENGTH);
});

test('embedding source is overview only and never includes identifiers', function (): void {
    $flatten = new FlattenExtractedTextForSearch;
    $source = $flatten->embeddingSource('statement.pdf', bankStatementExtractionJson());

    expect($source)->toContain('statement.pdf')
        ->and($source)->toContain('bank_statement')
        ->and($source)->toContain('Monthly salary payments for March.')
        ->and($source)->toContain('Salary credited on the first.')
        ->and($source)->not->toContain('NL18')
        ->and($source)->not->toContain('123456789')
        ->and($source)->not->toContain('2500');
});
