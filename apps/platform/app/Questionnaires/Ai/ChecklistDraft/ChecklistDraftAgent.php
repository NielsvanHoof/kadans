<?php

declare(strict_types=1);

namespace App\Questionnaires\Ai\ChecklistDraft;

use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Shared\Concerns\ResolvesConfiguredModel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

final readonly class ChecklistDraftAgent implements Agent, HasStructuredOutput
{
    use Promptable;
    use ResolvesConfiguredModel;

    public function __construct(
        private string $languageLabel,
    ) {}

    public function instructions(): string
    {
        $types = implode(', ', QuestionnaireItemType::values());

        return <<<PROMPT
            You suggest a starter checklist of document requests and questions
            that an accounting firm can ask a client to fulfill.

            Rules:
            - Always write the title and instructions in {$this->languageLabel},
              regardless of the language used in the description you are given.
            - Suggest between 3 and 15 items.
            - Each item needs a short, actionable title and a one-sentence
              instruction explaining what the client should provide.
            - Pick the most fitting "type" for each item from this exact
              list: {$types}. Use "file" when in doubt.
            - Do not use markdown formatting.
            PROMPT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()
                ->items(
                    $schema->object(fn ($schema): array => [
                        'title' => $schema->string()->required(),
                        'instructions' => $schema->string()->required(),
                        'type' => $schema->string()->required(),
                    ]),
                )
                ->min(3)
                ->max(15)
                ->required(),
        ];
    }

    protected function agentConfigKey(): string
    {
        return 'checklist_draft';
    }
}
