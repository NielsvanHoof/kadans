<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Services\Ocr\Normalization;

use App\DocumentProcessing\Ai\Ocr\DocumentOverviewAgent;
use App\DocumentProcessing\Contracts\Ocr\DocumentExtraction;
use App\DocumentProcessing\Contracts\Ocr\DocumentOverviewNormalizer;
use App\Tenancy\Enums\TenantAiFeature;
use App\Tenancy\Queries\Settings\ResolveTenantSettingsQuery;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

use function count;
use function is_array;
use function is_string;
use function mb_strlen;

/**
 * Asks DocumentOverviewAgent for document_type / summary / notes only.
 * Fields and tables stay Textract-owned so confidence is preserved.
 *
 * The agent's structured output is schema-constrained, so there is no response
 * parsing here — only mapping onto the DocumentOverview shape.
 *
 * @phpstan-import-type DocumentExtractionPayload from DocumentExtraction
 * @phpstan-import-type DocumentOverview from DocumentExtraction
 */
final readonly class AgentDocumentOverviewNormalizer implements DocumentOverviewNormalizer
{
    public function __construct(
        private ResolveTenantSettingsQuery $resolveTenantSettings,
    ) {}

    /**
     * @param  DocumentExtractionPayload  $payload
     * @return DocumentOverview
     */
    public function describe(array $payload): array
    {
        if (! $this->resolveTenantSettings->isAiFeatureEnabled(TenantAiFeature::DocumentOverview)) {
            Log::info('OCR: document overview skipped — AI feature disabled for firm.');

            return $this->emptyOverview();
        }

        $context = $this->buildContext($payload);

        if ($context === '') {
            Log::info('OCR: document overview skipped — empty Textract context.');

            return $this->emptyOverview();
        }

        $agent = new DocumentOverviewAgent;

        // Context may include PII labels — log length only, never the prompt body.
        Log::info('OCR: prompting the document overview agent.', [
            'model' => $agent->model(),
            'context_length' => mb_strlen($context),
        ]);

        $startedAt = hrtime(true);
        $response = $agent->prompt($this->userPrompt($context));
        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        throw_unless(
            $response instanceof StructuredAgentResponse,
            RuntimeException::class,
            'Document overview agent did not return structured output.',
        );

        $overview = $this->normalize($response->toArray());

        Log::info('OCR: document overview completed.', [
            'model' => $agent->model(),
            'duration_ms' => $durationMs,
            'document_type' => $overview['document_type'],
            'note_count' => count($overview['notes']),
        ]);

        return $overview;
    }

    private function userPrompt(string $context): string
    {
        return "Extracted form fields and table outlines:\n\n{$context}";
    }

    /**
     * Compact context so the agent sees labels/values without re-structuring them.
     *
     * @param  DocumentExtractionPayload  $payload
     */
    private function buildContext(array $payload): string
    {
        $lines = [];

        foreach ($payload['fields'] as $field) {
            $value = $field['value'];
            $rendered = is_array($value) ? implode(', ', $value) : $value;
            $lines[] = "- {$field['label']}: {$rendered}";
        }

        foreach ($payload['tables'] as $index => $table) {
            $title = $table['title'] ?? null;
            $label = is_string($title) && $title !== '' ? $title : 'Table '.($index + 1);
            $headerCount = count($table['headers']);
            $rowCount = count($table['rows']);
            $lines[] = "- [{$label}] headers={$headerCount} rows={$rowCount}";
        }

        return implode("\n", $lines);
    }

    /**
     * The schema constrains types but not "trimmed and non-empty", so blank
     * strings and non-string note entries still need filtering.
     *
     * @param  array<string, mixed>  $structured
     * @return DocumentOverview
     */
    private function normalize(array $structured): array
    {
        return [
            'document_type' => $this->nullableString($structured['document_type'] ?? null),
            'summary' => $this->nullableString($structured['summary'] ?? null),
            'notes' => $this->normalizeStringList($structured['notes'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = mb_trim($value);

            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }

        return $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = mb_trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return DocumentOverview
     */
    private function emptyOverview(): array
    {
        return [
            'document_type' => null,
            'summary' => null,
            'notes' => [],
        ];
    }
}
