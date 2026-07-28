<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\DocumentRequests;

use App\Dossiers\Models\DocumentRequest;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Illuminate\Support\Collection;

/**
 * Resolves one field's simple, earlier-answer visibility condition.
 */
final class DetermineDocumentRequestVisibilityAction
{
    /**
     * @param  Collection<int, DocumentRequest>  $documentRequests
     */
    public function handle(
        DocumentRequest $documentRequest,
        Collection $documentRequests,
    ): bool {
        $visibility = $documentRequest->configuration['visibility'] ?? null;

        if (! is_array($visibility)) {
            return true;
        }

        $sourceFieldKey = $visibility['source_field_key'] ?? null;
        $optionIds = $visibility['option_ids'] ?? null;

        if (! is_string($sourceFieldKey) || ! is_array($optionIds)) {
            return false;
        }

        $source = $documentRequests->first(
            fn (DocumentRequest $candidate): bool => $candidate->field_key === $sourceFieldKey,
        );

        if (! $source instanceof DocumentRequest) {
            return false;
        }

        return array_intersect($optionIds, $this->answerOptionIds($source)) !== [];
    }

    /** @return list<string> */
    private function answerOptionIds(DocumentRequest $documentRequest): array
    {
        if ($documentRequest->type === QuestionnaireItemType::Boolean) {
            if ($documentRequest->answer_boolean === null) {
                return [];
            }

            return [$documentRequest->answer_boolean
                ? QuestionnaireItemType::BooleanYesOptionId
                : QuestionnaireItemType::BooleanNoOptionId];
        }

        return $documentRequest->answer_option_ids ?? [];
    }
}
