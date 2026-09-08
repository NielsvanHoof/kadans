<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Services\Ocr;

use App\DocumentProcessing\Contracts\Ocr\DocumentExtraction;
use JsonException;

use function is_array;
use function is_string;

/**
 * Turns OCR JSON plus the original filename into two search representations.
 *
 * searchableText() is lexical (IBANs, invoice numbers, table cells).
 * embeddingSource() is the overview only — never field values — so Titan
 * embeddings do not get identifiers the keyword column already holds.
 *
 * @phpstan-import-type DocumentExtractionPayload from DocumentExtraction
 */
final readonly class FlattenExtractedTextForSearch
{
    public const int MAX_SEARCHABLE_LENGTH = 32_000;

    public function searchableText(string $filename, ?string $extractedJson): string
    {
        $parts = [$filename];
        $payload = $this->decode($extractedJson);

        if ($payload === null) {
            if (is_string($extractedJson) && mb_trim($extractedJson) !== '') {
                $parts[] = $extractedJson;
            }

            return $this->cap($this->join($parts));
        }

        $this->appendOverview($parts, $payload);
        $this->appendFields($parts, $payload['fields'] ?? []);
        $this->appendTables($parts, $payload['tables'] ?? []);

        return $this->cap($this->join($parts));
    }

    public function embeddingSource(string $filename, ?string $extractedJson): string
    {
        $parts = [$filename];
        $payload = $this->decode($extractedJson);

        if ($payload !== null) {
            $this->appendOverview($parts, $payload);
        }

        return $this->join($parts);
    }

    /**
     * Safe display fields for search hits — overview only, no identifiers.
     *
     * @return array{document_type: string|null, summary: string|null}
     */
    public function overview(?string $extractedJson): array
    {
        $payload = $this->decode($extractedJson);

        if ($payload === null) {
            return [
                'document_type' => null,
                'summary' => null,
            ];
        }

        $documentType = $payload['document_type'] ?? null;
        $summary = $payload['summary'] ?? null;

        return [
            'document_type' => is_string($documentType) && $documentType !== '' ? $documentType : null,
            'summary' => is_string($summary) && $summary !== '' ? $summary : null,
        ];
    }

    /**
     * @param  list<string>  $parts
     * @param  array<string, mixed>  $payload
     */
    private function appendOverview(array &$parts, array $payload): void
    {
        foreach (['document_type', 'summary'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && mb_trim($value) !== '') {
                $parts[] = $value;
            }
        }

        $notes = $payload['notes'] ?? null;

        if (! is_array($notes)) {
            return;
        }

        foreach ($notes as $note) {
            if (is_string($note) && mb_trim($note) !== '') {
                $parts[] = $note;
            }
        }
    }

    /**
     * @param  list<string>  $parts
     */
    private function appendFields(array &$parts, mixed $fields): void
    {
        if (! is_array($fields)) {
            return;
        }

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $label = $field['label'] ?? null;

            if (is_string($label) && mb_trim($label) !== '') {
                $parts[] = $label;
            }

            foreach ($this->stringValues($field['value'] ?? null) as $value) {
                $parts[] = $value;
                $compact = $this->compactIdentifier($value);

                if ($compact !== null) {
                    $parts[] = $compact;
                }
            }
        }
    }

    /**
     * @param  list<string>  $parts
     */
    private function appendTables(array &$parts, mixed $tables): void
    {
        if (! is_array($tables)) {
            return;
        }

        foreach ($tables as $table) {
            if (! is_array($table)) {
                continue;
            }

            $title = $table['title'] ?? null;

            if (is_string($title) && mb_trim($title) !== '') {
                $parts[] = $title;
            }

            foreach (['headers', 'rows'] as $key) {
                $this->appendNestedStrings($parts, $table[$key] ?? null);
            }
        }
    }

    /**
     * @param  list<string>  $parts
     */
    private function appendNestedStrings(array &$parts, mixed $value): void
    {
        if (is_string($value) && mb_trim($value) !== '') {
            $parts[] = $value;
            $compact = $this->compactIdentifier($value);

            if ($compact !== null) {
                $parts[] = $compact;
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $nested) {
            $this->appendNestedStrings($parts, $nested);
        }
    }

    /**
     * @return list<string>
     */
    private function stringValues(mixed $value): array
    {
        if (is_string($value) && mb_trim($value) !== '') {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $values = [];

        foreach ($value as $nested) {
            if (is_string($nested) && mb_trim($nested) !== '') {
                $values[] = $nested;
            }
        }

        return $values;
    }

    private function compactIdentifier(string $value): ?string
    {
        $compact = str_replace([' ', "\t", "\n", "\r"], '', $value);

        if ($compact === $value || $compact === '') {
            return null;
        }

        return $compact;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(?string $extractedJson): ?array
    {
        if (! is_string($extractedJson) || mb_trim($extractedJson) === '') {
            return null;
        }

        try {
            $decoded = json_decode($extractedJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  list<string>  $parts
     */
    private function join(array $parts): string
    {
        $filtered = [];

        foreach ($parts as $part) {
            $trimmed = mb_trim($part);

            if ($trimmed !== '') {
                $filtered[] = $trimmed;
            }
        }

        return implode("\n", $filtered);
    }

    private function cap(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_SEARCHABLE_LENGTH) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_SEARCHABLE_LENGTH);
    }
}
