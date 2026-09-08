<?php

declare(strict_types=1);

namespace App\Questionnaires\Actions\Templates;

use App\Questionnaires\Enums\QuestionnaireTemplateCategory;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Tenancy\Models\Tenant;

/**
 * Persist a firm-owned questionnaire template for the given tenant.
 */
final class CreateQuestionnaireTemplateAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Tenant $tenant, array $attributes): QuestionnaireTemplate
    {
        $template = QuestionnaireTemplate::query()->make();
        $template->name = (string) $attributes['name'];
        $template->description = isset($attributes['description'])
            ? (string) $attributes['description']
            : null;
        $template->category = QuestionnaireTemplateCategory::from((string) $attributes['category']);
        $template->tenant_id = $tenant->getKey();
        $template->save();

        return $template;
    }
}
