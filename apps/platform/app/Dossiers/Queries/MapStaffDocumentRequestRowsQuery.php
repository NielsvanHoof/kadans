<?php

declare(strict_types=1);

namespace App\Dossiers\Queries;

use App\Dossiers\Actions\DocumentRequests\DetermineDocumentRequestVisibilityAction;
use App\Dossiers\Actions\DocumentRequests\ResolveApplicableDocumentRequestsAction;
use App\Dossiers\Data\DossierReviewSummaryData;
use App\Dossiers\Data\StaffDocumentRequestData;
use App\Dossiers\Data\StaffDocumentRequestRowsData;
use App\Dossiers\Data\StaffUploadedDocumentData;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\UploadedDocument;
use Illuminate\Support\Collection;

use function in_array;

/**
 * Query to build staff document-request rows and progress counters for dossier page queries.
 */
final readonly class MapStaffDocumentRequestRowsQuery
{
    public function __construct(
        private DetermineDocumentRequestVisibilityAction $determineVisibility,
        private ResolveApplicableDocumentRequestsAction $resolveApplicableDocumentRequests,
    ) {}

    /**
     * @param  Collection<int, DocumentRequest>  $documentRequests
     */
    public function handle(
        DossierStatus $dossierStatus,
        Collection $documentRequests,
        bool $includeUploadedDocuments = true,
    ): StaffDocumentRequestRowsData {
        // Progress / completion only count applicable (visible + required-or-answered) fields.
        $applicableDocumentRequests = $this->resolveApplicableDocumentRequests->handle(
            $documentRequests,
        );
        $applicableIds = $applicableDocumentRequests
            ->map(fn (DocumentRequest $documentRequest): int => $documentRequest->id)
            ->all();
        $totalRequestCount = $applicableDocumentRequests->count();
        $acceptedRequestCount = $applicableDocumentRequests
            ->where('status', DocumentRequestStatus::Accepted)
            ->count();
        $outstandingClientItemCount = $applicableDocumentRequests
            ->whereIn('status', [
                DocumentRequestStatus::Pending,
                DocumentRequestStatus::Rejected,
            ])
            ->count();

        /** @var list<StaffDocumentRequestData> $rows */
        $rows = [];

        foreach ($documentRequests as $documentRequest) {
            $isVisible = $this->determineVisibility->handle(
                $documentRequest,
                $documentRequests,
            );
            $isApplicable = in_array($documentRequest->id, $applicableIds, true);

            $uploaded = $includeUploadedDocuments
                ? $documentRequest->uploadedDocuments
                : collect();

            $rows[] = StaffDocumentRequestData::fromModel(
                $documentRequest,
                $isVisible,
                $isApplicable,
                array_values($uploaded
                    ->map(fn (UploadedDocument $uploadedDocument): StaffUploadedDocumentData => StaffUploadedDocumentData::fromModel($uploadedDocument))
                    ->all()),
            );
        }

        return new StaffDocumentRequestRowsData(
            rows: $rows,
            reviewSummary: new DossierReviewSummaryData(
                total: $totalRequestCount,
                pending: $applicableDocumentRequests->where('status', DocumentRequestStatus::Pending)->count(),
                submitted: $applicableDocumentRequests->where('status', DocumentRequestStatus::Submitted)->count(),
                accepted: $acceptedRequestCount,
                rejected: $applicableDocumentRequests->where('status', DocumentRequestStatus::Rejected)->count(),
            ),
            hasOutstandingClientItems: $outstandingClientItemCount > 0,
            readyToComplete: $dossierStatus !== DossierStatus::Completed
                && $totalRequestCount > 0
                && $acceptedRequestCount === $totalRequestCount,
        );
    }
}
