<?php

declare(strict_types=1);

namespace App\Questionnaires\Actions\Templates;

use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Questionnaires\Models\QuestionnaireTemplateItem;
use Illuminate\Support\Facades\DB;

use function is_array;

final readonly class CreateQuestionnaireTemplateItemAction
{
    public function __construct(
        private NormalizeQuestionnaireItemConfigurationAction $normalizeConfiguration,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        QuestionnaireTemplate $template,
        array $attributes,
    ): QuestionnaireTemplateItem {
        $type = QuestionnaireItemType::from((string) $attributes['type']);

        $attributes['configuration'] = $this->normalizeConfiguration->handle(
            $type,
            is_array($attributes['configuration'] ?? null)
                ? $attributes['configuration']
                : [],
        );

        return DB::transaction(function () use ($template, $attributes): QuestionnaireTemplateItem {
            $templateQuery = QuestionnaireTemplate::query()->whereKey($template->getKey());
            $templateQuery->getQuery()->lockForUpdate();
            $lockedTemplate = $templateQuery->firstOrFail();

            $nextSortOrder = (int) $lockedTemplate
                ->items()
                ->toBase()
                ->max('sort_order') + 1;

            return $lockedTemplate->items()->create([
                ...$attributes,
                'sort_order' => $nextSortOrder,
            ]);
        });
    }
}
