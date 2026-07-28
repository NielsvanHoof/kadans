<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Builder;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Models\QuestionnaireTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ApplyQuestionnaireTemplateToDossierAction
{
    public function __construct(
        private readonly LogAuditActivityAction $logAuditActivity,
    ) {}

    /**
     * Append template items as document requests. Never removes existing rows.
     * Resolves the template by id so Dossiers controllers stay out of Questionnaires.
     *
     * @return Collection<int, DocumentRequest>
     */
    public function handle(Dossier $dossier, int $templateId): Collection
    {
        $template = QuestionnaireTemplate::queryVisibleToCurrentTenant()
            ->whereKey($templateId)
            ->firstOrFail();

        $template->loadMissing('items');

        return DB::transaction(function () use ($dossier, $template): Collection {
            $dossierQuery = Dossier::query()->whereKey($dossier->getKey());
            $dossierQuery->getQuery()->lockForUpdate();
            $lockedDossier = $dossierQuery->firstOrFail();

            $nextSortOrder = (int) $lockedDossier
                ->documentRequests()
                ->toBase()
                ->max('sort_order') + 1;

            $created = collect();

            foreach ($template->items as $item) {
                $documentRequest = $lockedDossier->documentRequests()->create([
                    'type' => $item->type,
                    'title' => $item->title,
                    'instructions' => $item->instructions,
                    'status' => DocumentRequestStatus::Pending,
                    'sort_order' => $nextSortOrder,
                ]);

                $this->logAuditActivity->handle(
                    AuditEvent::DocumentRequestCreated,
                    $documentRequest,
                );

                $created->push($documentRequest);
                $nextSortOrder++;
            }

            return $created;
        });
    }
}
