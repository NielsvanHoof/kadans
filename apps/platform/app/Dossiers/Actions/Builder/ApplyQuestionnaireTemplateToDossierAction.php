<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Builder;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Support\DossierLocks;
use App\Questionnaires\Models\QuestionnaireTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function array_key_exists;
use function is_string;

final readonly class ApplyQuestionnaireTemplateToDossierAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private DossierLocks $dossierLocks,
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
            $lockedDossier = $this->dossierLocks->dossier($dossier);

            $nextSortOrder = (int) $lockedDossier
                ->documentRequests()
                ->toBase()
                ->max('sort_order') + 1;

            $created = collect();

            // One fresh ULID per template item, keyed by item id. Mapping by
            // field_key collapses when keys are null/duplicate (e.g. seeders
            // that run under WithoutModelEvents), which then violates the
            // unique index on document_requests.field_key.
            $fieldKeysByItemId = $template->items
                ->mapWithKeys(fn ($item): array => [$item->getKey() => (string) Str::ulid()])
                ->all();

            // Visibility rules still reference template field keys, so rewrite
            // those to the new per-item keys when the template key is present.
            $fieldKeysForVisibility = $template->items
                ->filter(fn ($item): bool => $item->field_key !== '')
                ->mapWithKeys(fn ($item): array => [
                    $item->field_key => $fieldKeysByItemId[$item->getKey()],
                ])
                ->all();

            foreach ($template->items as $item) {
                $configuration = $this->remapVisibility(
                    $item->configuration ?? [],
                    $fieldKeysForVisibility,
                );
                $documentRequest = $lockedDossier->documentRequests()->create([
                    'type' => $item->type,
                    'field_key' => $fieldKeysByItemId[$item->getKey()],
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
     * @param  array<string, string>  $fieldKeys  Template field key => new field key
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
