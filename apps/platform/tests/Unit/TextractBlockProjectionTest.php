<?php

declare(strict_types=1);

use App\DocumentProcessing\Services\Ocr\Normalization\TextractFormsTablesMapper;
use App\DocumentProcessing\Services\Ocr\Textract\TextractJobBlockFetcher;
use Tests\TestCase;

covers(TextractJobBlockFetcher::class);

pest()
    ->extend(TestCase::class);

/**
 * A Textract block as the API really returns it: the fields the mapper reads,
 * plus the ones it never touches. Geometry is the expensive one — six nested
 * arrays on every block of every page.
 *
 * @return list<array<string, mixed>>
 */
function textractBlocksWithProviderNoise(): array
{
    $geometry = [
        'BoundingBox' => ['Width' => 0.12, 'Height' => 0.02, 'Left' => 0.1, 'Top' => 0.2],
        'Polygon' => [
            ['X' => 0.1, 'Y' => 0.2],
            ['X' => 0.22, 'Y' => 0.2],
            ['X' => 0.22, 'Y' => 0.22],
            ['X' => 0.1, 'Y' => 0.22],
        ],
    ];

    return [
        [
            'Id' => 'key-1',
            'BlockType' => 'KEY_VALUE_SET',
            'EntityTypes' => ['KEY'],
            'Relationships' => [
                ['Type' => 'CHILD', 'Ids' => ['word-key']],
                ['Type' => 'VALUE', 'Ids' => ['value-1']],
            ],
            'Geometry' => $geometry,
            'Page' => 1,
        ],
        [
            'Id' => 'value-1',
            'BlockType' => 'KEY_VALUE_SET',
            'EntityTypes' => ['VALUE'],
            'Confidence' => 98.0,
            'Relationships' => [['Type' => 'CHILD', 'Ids' => ['word-value']]],
            'Geometry' => $geometry,
            'Page' => 1,
        ],
        [
            'Id' => 'word-key',
            'BlockType' => 'WORD',
            'Text' => 'BSN',
            'TextType' => 'PRINTED',
            'Confidence' => 99.1,
            'Geometry' => $geometry,
            'Page' => 1,
        ],
        [
            'Id' => 'word-value',
            'BlockType' => 'WORD',
            'Text' => '123456789',
            'TextType' => 'PRINTED',
            'Confidence' => 99.4,
            'Geometry' => $geometry,
            'Page' => 1,
        ],
        [
            'Id' => 'table-1',
            'BlockType' => 'TABLE',
            'Relationships' => [['Type' => 'CHILD', 'Ids' => ['c11', 'c12']]],
            'Geometry' => $geometry,
            'Page' => 1,
        ],
        [
            'Id' => 'c11',
            'BlockType' => 'CELL',
            'RowIndex' => 1,
            'ColumnIndex' => 1,
            'RowSpan' => 1,
            'ColumnSpan' => 1,
            'EntityTypes' => ['COLUMN_HEADER'],
            'Relationships' => [['Type' => 'CHILD', 'Ids' => ['w-a']]],
            'Geometry' => $geometry,
        ],
        [
            'Id' => 'c12',
            'BlockType' => 'CELL',
            'RowIndex' => 2,
            'ColumnIndex' => 1,
            'RowSpan' => 1,
            'ColumnSpan' => 1,
            'Relationships' => [['Type' => 'CHILD', 'Ids' => ['w-b']]],
            'Geometry' => $geometry,
        ],
        ['Id' => 'w-a', 'BlockType' => 'WORD', 'Text' => 'Gross', 'Geometry' => $geometry],
        ['Id' => 'w-b', 'BlockType' => 'WORD', 'Text' => '4200', 'Geometry' => $geometry],
    ];
}

/**
 * @param  list<array<string, mixed>>  $blocks
 * @return list<array<string, mixed>>
 */
function projectTextractBlocks(array $blocks): array
{
    return array_map(
        static fn (array $block): array => array_intersect_key(
            $block,
            TextractJobBlockFetcher::RETAINED_BLOCK_KEYS,
        ),
        $blocks,
    );
}

test('the retained key mask covers every block key the mapper reads', function (): void {
    // The safety net for the projection: if someone teaches the mapper to read
    // Geometry or Page, this fails rather than the mapper quietly seeing null.
    // Source-scanned for the same reason WorkerQueueCoverageTest scans jobs —
    // the coupling is real but not expressible in a type.
    $source = (string) file_get_contents(
        app_path('DocumentProcessing/Services/Ocr/Normalization/TextractFormsTablesMapper.php'),
    );

    preg_match_all("/\\['([A-Z][A-Za-z]*)'\\]/", $source, $matches);

    $readKeys = array_values(array_unique($matches[1]));

    expect($readKeys)->not->toBeEmpty();

    // Type and Ids live inside a Relationships entry, which is retained whole.
    $covered = [...array_keys(TextractJobBlockFetcher::RETAINED_BLOCK_KEYS), 'Type', 'Ids'];

    foreach ($readKeys as $key) {
        expect($covered)->toContain($key);
    }
});

test('projecting blocks does not change what the mapper produces', function (): void {
    $mapper = resolve(TextractFormsTablesMapper::class);
    $blocks = textractBlocksWithProviderNoise();

    expect($mapper->map(projectTextractBlocks($blocks)))->toBe($mapper->map($blocks));
});

test('projection drops the provider fields nothing reads', function (): void {
    $projected = projectTextractBlocks(textractBlocksWithProviderNoise());

    foreach ($projected as $block) {
        expect($block)->not->toHaveKey('Geometry')
            ->and($block)->not->toHaveKey('Page')
            ->and($block)->not->toHaveKey('TextType')
            ->and($block)->not->toHaveKey('RowSpan')
            ->and($block)->not->toHaveKey('ColumnSpan');
    }

    // Still carries what the mapper needs.
    expect($projected[0])->toHaveKeys(['Id', 'BlockType', 'EntityTypes', 'Relationships'])
        ->and($projected[1]['Confidence'])->toBe(98.0)
        ->and($projected[5])->toHaveKeys(['RowIndex', 'ColumnIndex']);
});
