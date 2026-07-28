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
use Illuminate\Support\Str;

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

            // Copied fields get fresh keys, so conditions must be rewritten to
            // point at the copies rather than back at the template items.
            $fieldKeys = $template->items
                ->mapWithKeys(fn ($item): array => [$item->field_key => (string) Str::ulid()])
                ->all();

            foreach ($template->items as $item) {
                $configuration = $this->remapVisibility(
                    $item->configuration ?? [],
                    $fieldKeys,
                );
                $documentRequest = $lockedDossier->documentRequests()->create([
                    'type' => $item->type,
                    'field_key' => $fieldKeys[$item->field_key] ?? null,
                    'title' => $item->title,
                    'instructions' => $item->instructions,
                    'configuration' => $configuration,
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

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<array-key, string>  $fieldKeys  Template field key => new field key
     * @return array<string, mixed>
     */
    private function remapVisibility(array $configuration, array $fieldKeys): array
    {
        $sourceFieldKey = $configuration['visibility']['source_field_key'] ?? null;

        if (! is_string($sourceFieldKey) || ! array_key_exists($sourceFieldKey, $fieldKeys)) {
            return $configuration;
        }

        $configuration['visibility']['source_field_key'] = $fieldKeys[$sourceFieldKey];

        return $configuration;
    }
}
