<?php

declare(strict_types=1);

namespace App\Questionnaires\Actions\Templates;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Questionnaires\Models\QuestionnaireTemplateItem;

/**
 * Delete a template item and write the matching audit event.
 */
final readonly class DeleteQuestionnaireTemplateItemAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    public function handle(
        QuestionnaireTemplate $template,
        QuestionnaireTemplateItem $item,
    ): void {
        $this->logAuditActivity->handle(
            AuditEvent::QuestionnaireTemplateItemDeleted,
            $item,
            [
                'title' => $item->title,
                'template_id' => $template->id,
            ],
        );

        $item->delete();
    }
}
