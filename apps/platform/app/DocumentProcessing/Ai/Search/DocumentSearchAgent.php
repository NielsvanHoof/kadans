<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Ai\Search;

use App\DocumentProcessing\Ai\Search\Tools\SearchUploadedDocuments;
use App\Shared\Concerns\ResolvesConfiguredModel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ProviderTool;

/**
 * Answers a staff question about documents in the current workspace.
 *
 * Retrieval goes through SearchUploadedDocuments (hybrid Scout), not embeddings
 * of IBAN-laden flatten. The model only sees safe hit metadata.
 */
#[MaxTokens(1024)]
#[MaxSteps(6)]
#[Temperature(0)]
final readonly class DocumentSearchAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;
    use ResolvesConfiguredModel;

    public function __construct(
        private string $languageLabel,
    ) {}

    public function instructions(): string
    {
        return <<<PROMPT
            You help accounting-firm staff find uploaded client documents.

            Rules:
            - Always call the search tool before answering.
            - The tool matches the query as one substring (filename or OCR
              text). Never pass a full sentence. Pass one short token per call:
              a client name (Hoekstra), a filename fragment (leasecontract),
              or a type (loonstrook, bankafschrift).
            - If the first call is empty, try a different short token. You may
              call the tool more than once.
            - Write the answer in {$this->languageLabel}. Always write a
              sentence in the answer field — never leave it blank.
            - Only cite documents the tool actually returned. Never invent ids.
            - Do not quote IBAN, BSN, account numbers, or other extracted field
              values even if they appeared in the question.
            - If nothing matches, say so and leave citations empty.
            PROMPT;
    }

    /**
     * @return list<Agent|Tool|ProviderTool>
     */
    public function tools(): iterable
    {
        return [
            new SearchUploadedDocuments,
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'answer' => $schema->string()->required(),
            'citations' => $schema->array()
                ->items(
                    $schema->object(fn ($schema): array => [
                        'uploaded_document_id' => $schema->integer()->required(),
                        'why' => $schema->string()->required(),
                    ]),
                )
                ->required(),
        ];
    }

    protected function agentConfigKey(): string
    {
        return 'document_search';
    }
}
