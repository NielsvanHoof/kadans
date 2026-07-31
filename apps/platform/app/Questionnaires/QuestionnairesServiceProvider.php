<?php

declare(strict_types=1);

namespace App\Questionnaires;

use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Questionnaires\Policies\QuestionnaireTemplatePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Questionnaires domain authorization.
 */
final class QuestionnairesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(QuestionnaireTemplate::class, QuestionnaireTemplatePolicy::class);
    }
}
