<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Builder;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Data\StaffDocumentRequestData;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Queries\MapStaffDocumentRequestRowsQuery;
use App\Dossiers\Support\DossierLocks;
use App\Questionnaires\Actions\NormalizeQuestionnaireItemConfigurationAction;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

use function in_array;
use function is_array;
use function is_numeric;

/**
 * Replace a dossier's questionnaire canvas from a draft Save payload.
 * Creates, updates, deletes, and reorders in one transaction.
 */
final readonly class SyncDossierDocumentRequestsAction
{
    public function __construct(
        private NormalizeQuestionnaireItemConfigurationAction $normalizeConfiguration,
        private MapStaffDocumentRequestRowsQuery $mapStaffDocumentRequestRows,
        private LogAuditActivityAction $logAuditActivity,
        private FilesystemManager $filesystems,
        private DossierLocks $dossierLocks,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function handle(Dossier $dossier, array $items): array
    {
        /** @var list<array{disk: string, path: string}> $filesToDelete */
        $filesToDelete = DB::transaction(function () use ($dossier, $items): array {
            $lockedDossier = $this->dossierLocks->dossier($dossier);

            $existing = DocumentRequest::query()
                ->whereBelongsTo($lockedDossier)
                ->with(['uploadedDocuments', 'reviewedBy:id,name'])
                ->get()
                ->keyBy('id');

            $keptIds = [];

            foreach ($items as $item) {
                $id = $item['id'] ?? null;

                if (is_numeric($id) && (int) $id > 0) {
                    $keptIds[] = (int) $id;
                }
            }

            $filesToDelete = [];

            foreach ($existing as $documentRequest) {
                if (in_array($documentRequest->id, $keptIds, true)) {
                    continue;
                }

                $filesToDelete = [
                    ...$filesToDelete,
                    ...$this->deleteDocumentRequest($documentRequest),
                ];
            }

            foreach (array_values($items) as $sortOrder => $item) {
                $type = QuestionnaireItemType::from((string) $item['type']);
                $configuration = $this->normalizeConfiguration->handle(
                    $type,
                    is_array($item['configuration'] ?? null)
                        ? $item['configuration']
                        : [],
                );

                $attributes = [
                    'type' => $type,
                    'title' => (string) $item['title'],
                    'instructions' => $item['instructions'] ?? null,
                    'configuration' => $configuration,
                    'sort_order' => $sortOrder,
                ];

                $id = $item['id'] ?? null;

                if (is_numeric($id) && (int) $id > 0) {
                    /** @var DocumentRequest $documentRequest */
                    $documentRequest = $existing->get((int) $id);
                    $documentRequest->update($attributes);

                    continue;
                }

                $created = $lockedDossier->documentRequests()->create([
                    ...$attributes,
                    'field_key' => (string) $item['field_key'],
                    'status' => DocumentRequestStatus::Pending,
                ]);

                $this->logAuditActivity->handle(
                    AuditEvent::DocumentRequestCreated,
                    $created,
                );
            }

            return $filesToDelete;
        });

        foreach ($filesToDelete as $storedFile) {
            $this->deleteStoredFile($storedFile);
        }

        $dossier->unsetRelation('documentRequests');
        $documentRequests = DocumentRequest::query()
            ->whereBelongsTo($dossier)
            ->with(['uploadedDocuments', 'reviewedBy:id,name'])
            ->with('uploadedDocuments.pipelines.steps')
            ->oldest('sort_order')
            ->get();

        $mapped = $this->mapStaffDocumentRequestRows->handle(
            $dossier,
            $documentRequests,
        );

        return array_map(
            static fn (StaffDocumentRequestData $row): array => $row->toArray(),
            $mapped->rows,
        );
    }

    /**
     * @return list<array{disk: string, path: string}>
     */
    private function deleteDocumentRequest(DocumentRequest $documentRequest): array
    {
        $files = array_values($documentRequest->uploadedDocuments
            ->map(fn (UploadedDocument $uploadedDocument): array => [
                'disk' => $uploadedDocument->disk,
                'path' => $uploadedDocument->path,
            ])
            ->all());

        $this->logAuditActivity->handle(
            AuditEvent::DocumentRequestDeleted,
            $documentRequest,
            [
                'title' => $documentRequest->title,
                'type' => $documentRequest->type->value,
                'dossier_id' => $documentRequest->dossier_id,
            ],
        );

        $documentRequest->deleteOrFail();

        return $files;
    }

    /**
     * @param  array{disk: string, path: string}  $storedFile
     */
    private function deleteStoredFile(array $storedFile): void
    {
        try {
            $deleted = $this->filesystems
                ->disk($storedFile['disk'])
                ->delete($storedFile['path']);
        } catch (Throwable $exception) {
            report(new RuntimeException(
                'Failed to remove the stored file for a deleted document request.',
                previous: $exception,
            ));

            return;
        }

        report_unless($deleted, new RuntimeException(
            'Failed to remove the stored file for a deleted document request.',
        ));
    }
}
