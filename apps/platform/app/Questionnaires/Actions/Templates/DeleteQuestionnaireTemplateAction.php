<?php

declare(strict_types=1);

namespace App\Questionnaires\Actions;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Questionnaires\Models\QuestionnaireTemplate;

/**
 * Soft-delete a questionnaire template and write the matching audit event.
 */
final readonly class DeleteQuestionnaireTemplateAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    public function handle(QuestionnaireTemplate $template): void
    {
        $this->logAuditActivity->handle(
            AuditEvent::QuestionnaireTemplateDeleted,
            $template,
            [
                'name' => $template->name,
                'category' => $template->category->value,
            ],
        );

        $template->delete();
    }
}
