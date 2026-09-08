<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Ai\Ocr;

use App\Shared\Concerns\ResolvesConfiguredModel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Classifies a Textract extraction: document_type / summary / notes only.
 * Fields and tables stay Textract-owned so confidence is preserved.
 *
 * Temperature is pinned to 0 because the output feeds stored document metadata.
 */
#[MaxTokens(2048)]
#[Temperature(0)]
final readonly class DocumentOverviewAgent implements Agent, HasStructuredOutput
{
    use Promptable;
    use ResolvesConfiguredModel;

    public function instructions(): string
    {
        return <<<'PROMPT'
            You classify OCR extractions from Dutch and international financial/identity
            documents (payslips, bank statements, invoices, ID cards, tax forms).

            Rules:
            - document_type: short snake_case label when clear (payslip, bank_statement,
              invoice, identity, tax_form, other), else null.
            - summary: one short sentence describing the document, or null.
            - notes: short caveats about the extraction quality. Empty array when none.
            - Do not invent field values. Do not return fields or tables.
            - Keep summary language matching the document when obvious
              (Dutch documents → Dutch summary).
            PROMPT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_type' => $schema->string()->nullable()->required(),
            'summary' => $schema->string()->nullable()->required(),
            'notes' => $schema->array()->items($schema->string())->required(),
        ];
    }

    protected function agentConfigKey(): string
    {
        return 'document_overview';
    }
}
