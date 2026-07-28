<?php

declare(strict_types=1);

namespace App\Questionnaires\Actions;

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
        return QuestionnaireTemplate::query()->create([
            ...$attributes,
            'tenant_id' => $tenant->getKey(),
        ]);
    }
}
