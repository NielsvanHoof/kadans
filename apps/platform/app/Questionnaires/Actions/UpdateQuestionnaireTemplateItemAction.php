<?php

declare(strict_types=1);

namespace App\Questionnaires\Actions;

use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Questionnaires\Models\QuestionnaireTemplateItem;

use function is_array;

/**
 * Persist updated questionnaire template item attributes.
 */
final readonly class UpdateQuestionnaireTemplateItemAction
{
    public function __construct(
        private NormalizeQuestionnaireItemConfigurationAction $normalizeConfiguration,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(QuestionnaireTemplateItem $item, array $attributes): void
    {
        $type = QuestionnaireItemType::from((string) ($attributes['type'] ?? $item->type->value));

        $attributes['configuration'] = $this->normalizeConfiguration->handle(
            $type,
            is_array($attributes['configuration'] ?? null)
                ? $attributes['configuration']
                : ($item->configuration ?? []),
        );

        $item->update($attributes);
    }
}
