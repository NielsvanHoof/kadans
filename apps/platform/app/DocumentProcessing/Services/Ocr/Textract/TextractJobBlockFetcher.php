<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Services\Ocr\Textract;

use App\Platform\Support\PeakMemory;
use Aws\Textract\TextractClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

use function array_intersect_key;
use function count;
use function is_array;
use function is_string;

/**
 * Paginates GetDocumentAnalysis for a finished Textract AnalyzeDocument job.
 */
final readonly class TextractJobBlockFetcher
{
    /**
     * Block keys {@see \App\DocumentProcessing\Services\Ocr\Normalization\TextractFormsTablesMapper}
     * reads, as an `array_intersect_key` mask.
     *
     * Everything else is dropped as the page arrives, because the whole set
     * stays resident: the mapper indexes every block by id and holds that index
     * for the duration of the map. The expensive omission is `Geometry`, which
     * Textract attaches to every block as a BoundingBox of four floats plus a
     * Polygon of four X/Y pairs — six nested arrays per block, each its own PHP
     * hashtable, for a field nothing reads. `Page`, `TextType` and the span
     * fields go the same way.
     *
     * `Relationships` is kept whole: its entries only carry `Type` and `Ids`,
     * both of which the mapper needs.
     *
     * TextractBlockProjectionTest scans the mapper for the keys it actually
     * reads and fails if this mask stops covering them, so adding a read to the
     * mapper cannot silently start seeing nulls.
     *
     * @var array<string, true>
     */
    public const array RETAINED_BLOCK_KEYS = [
        'Id' => true,
        'BlockType' => true,
        'EntityTypes' => true,
        'Confidence' => true,
        'RowIndex' => true,
        'ColumnIndex' => true,
        'Text' => true,
        'SelectionStatus' => true,
        'Relationships' => true,
    ];

    public function __construct(
        private TextractClient $textract,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function fetch(string $jobId): array
    {
        $blocks = [];
        $nextToken = null;
        $pageCount = 0;

        Log::info('OCR: fetching Textract GetDocumentAnalysis pages.', [
            'textract_job_id' => $jobId,
        ]);

        do {
            $params = ['JobId' => $jobId];

            if ($nextToken !== null) {
                $params['NextToken'] = $nextToken;
            }

            $result = $this->textract->getDocumentAnalysis($params);
            $jobStatus = $result->get('JobStatus');
            $pageCount++;

            if ($jobStatus === 'FAILED') {
                $statusMessage = $result->get('StatusMessage');
                $error = is_string($statusMessage) && $statusMessage !== ''
                    ? $statusMessage
                    : 'Textract GetDocumentAnalysis failed.';

                Log::error('OCR: GetDocumentAnalysis returned FAILED.', [
                    'textract_job_id' => $jobId,
                    'error' => $error,
                ]);

                throw new RuntimeException($error);
            }

            $pageBlocks = $result->get('Blocks');

            if (is_array($pageBlocks)) {
                foreach ($pageBlocks as $block) {
                    if (is_array($block)) {
                        // Projected here rather than in the mapper: this is the
                        // only point where a page can be narrowed before it
                        // joins the set that stays resident.
                        $blocks[] = array_intersect_key($block, self::RETAINED_BLOCK_KEYS);
                    }
                }
            }

            $token = $result->get('NextToken');
            $nextToken = is_string($token) && $token !== '' ? $token : null;
        } while ($nextToken !== null);

        Log::info('OCR: fetched Textract analysis blocks.', [
            'textract_job_id' => $jobId,
            'block_count' => count($blocks),
            'api_page_count' => $pageCount,
            'job_status' => is_string($jobStatus) ? $jobStatus : null,
            'peak_memory_mb' => PeakMemory::megabytes(),
        ]);

        return $blocks;
    }
}
