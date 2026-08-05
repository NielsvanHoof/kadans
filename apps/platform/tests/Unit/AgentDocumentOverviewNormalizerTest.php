<?php

declare(strict_types=1);

use App\DocumentProcessing\Ai\DocumentOverviewAgent;
use App\DocumentProcessing\Services\Ocr\Normalization\AgentDocumentOverviewNormalizer;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

pest()
    ->extend(TestCase::class);

/**
 * @param  list<array{label: string, value: string, confidence: float|null, sensitivity: string|null}>  $fields
 * @return array<string, mixed>
 */
function overviewPayload(array $fields = [], array $tables = []): array
{
    return [
        'document_type' => null,
        'summary' => null,
        'fields' => $fields,
        'tables' => $tables,
        'notes' => [],
        'confidence' => null,
    ];
}

test('agent document overview normalizer returns empty overview without fields or tables', function (): void {
    DocumentOverviewAgent::fake();

    $overview = resolve(AgentDocumentOverviewNormalizer::class)->describe(overviewPayload());

    expect($overview)->toBe([
        'document_type' => null,
        'summary' => null,
        'notes' => [],
    ]);

    DocumentOverviewAgent::assertNeverPrompted();
});

test('agent document overview normalizer maps structured output and sends the textract context', function (): void {
    DocumentOverviewAgent::fake([[
        'document_type' => 'payslip',
        'summary' => 'January payslip',
        'notes' => ['Slight blur on footer'],
    ]]);

    $overview = resolve(AgentDocumentOverviewNormalizer::class)->describe(overviewPayload([
        ['label' => 'BSN', 'value' => '287505030', 'confidence' => 0.99, 'sensitivity' => null],
    ]));

    expect($overview)->toBe([
        'document_type' => 'payslip',
        'summary' => 'January payslip',
        'notes' => ['Slight blur on footer'],
    ]);

    DocumentOverviewAgent::assertPrompted(
        fn (AgentPrompt $prompt): bool => $prompt->contains('BSN: 287505030'),
    );
});

test('agent document overview normalizer normalizes blank and non-string values', function (): void {
    DocumentOverviewAgent::fake([[
        'document_type' => '   ',
        'summary' => null,
        'notes' => ['  Trimmed note  ', '', 42, null, 'Kept'],
    ]]);

    $overview = resolve(AgentDocumentOverviewNormalizer::class)->describe(overviewPayload([
        ['label' => 'Werkgever', 'value' => 'Acme BV', 'confidence' => 0.9, 'sensitivity' => null],
    ]));

    expect($overview)->toBe([
        'document_type' => null,
        'summary' => null,
        'notes' => ['Trimmed note', 'Kept'],
    ]);
});

test('agent document overview normalizer summarizes tables without field values', function (): void {
    DocumentOverviewAgent::fake([[
        'document_type' => 'bank_statement',
        'summary' => 'Overzicht van transacties',
        'notes' => [],
    ]]);

    $overview = resolve(AgentDocumentOverviewNormalizer::class)->describe(overviewPayload(
        tables: [
            ['title' => 'Transacties', 'headers' => ['Datum', 'Bedrag'], 'rows' => [['01-01', '10,00']]],
        ],
    ));

    expect($overview['document_type'])->toBe('bank_statement');

    DocumentOverviewAgent::assertPrompted(
        fn (AgentPrompt $prompt): bool => $prompt->contains('[Transacties] headers=2 rows=1'),
    );
});
