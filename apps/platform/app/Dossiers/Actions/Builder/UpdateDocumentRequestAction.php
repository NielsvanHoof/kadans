<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Builder;

use App\Dossiers\Models\DocumentRequest;
use App\Questionnaires\Actions\NormalizeQuestionnaireItemConfigurationAction;
use App\Questionnaires\Enums\QuestionnaireItemType;

use function is_array;

/**
 * Update a questionnaire item's editable attributes on the form builder.
 */
final class UpdateDocumentRequestAction
{
    public function __construct(
        private readonly NormalizeQuestionnaireItemConfigurationAction $normalizeConfiguration,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(DocumentRequest $documentRequest, array $attributes): void
    {
        $type = QuestionnaireItemType::from((string) ($attributes['type'] ?? $documentRequest->type->value));

        $attributes['configuration'] = $this->normalizeConfiguration->handle(
            $type,
            is_array($attributes['configuration'] ?? null)
                ? $attributes['configuration']
                : ($documentRequest->configuration ?? []),
        );

        $documentRequest->update($attributes);
    }
}
