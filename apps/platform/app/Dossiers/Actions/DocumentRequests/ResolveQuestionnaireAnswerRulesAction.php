<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\DocumentRequests;

use App\Dossiers\Models\DocumentRequest;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

use function is_array;
use function is_string;

/**
 * Resolves the validation rules for one questionnaire answer.
 *
 * Staff and portal submissions accept identical payloads, so both requests read
 * their rules from here. The match is exhaustive on purpose: adding a field type
 * breaks this action rather than silently falling back to free-text rules.
 */
final class ResolveQuestionnaireAnswerRulesAction
{
    /**
     * Shape matches Laravel form requests, so Rule::in() and string rules coexist.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function handle(DocumentRequest $documentRequest): array
    {
        return match ($documentRequest->type) {
            QuestionnaireItemType::Boolean => [
                'answer_boolean' => ['required', 'boolean'],
            ],
            QuestionnaireItemType::Choice, QuestionnaireItemType::MultiSelect => $this->optionRules($documentRequest),
            QuestionnaireItemType::Date => [
                'answer_text' => ['required', 'date_format:Y-m-d'],
            ],
            QuestionnaireItemType::Number => [
                'answer_text' => ['required', 'numeric'],
            ],
            // File answers arrive as uploads; both requests reject the type before this point.
            QuestionnaireItemType::Text, QuestionnaireItemType::Textarea, QuestionnaireItemType::File => [
                'answer_text' => ['required', 'string', 'max:5000'],
            ],
        };
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    private function optionRules(DocumentRequest $documentRequest): array
    {
        return [
            'answer_option_ids' => [
                'required',
                'array',
                'min:1',
                // Single choice takes exactly one option; multi-select takes several.
                $documentRequest->type === QuestionnaireItemType::Choice ? 'size:1' : 'max:50',
            ],
            // Restricting to configured ids rejects forged or stale options, which
            // otherwise store an answer that no longer matches any visible label.
            'answer_option_ids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::in($this->configuredOptionIds($documentRequest)),
            ],
        ];
    }

    /** @return list<string> */
    private function configuredOptionIds(DocumentRequest $documentRequest): array
    {
        $options = $documentRequest->configuration['options'] ?? [];

        if (! is_array($options)) {
            return [];
        }

        $ids = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $id = $option['id'] ?? null;

            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
