<?php

declare(strict_types=1);

namespace App\Dossiers\Queries\DocumentRequests;

use App\Clients\Models\Client;
use App\Dossiers\Data\DocumentRequests\DossierReviewData;
use App\Dossiers\Data\Lifecycle\DossierClientSummaryData;
use App\Dossiers\Enums\DocumentRequestPermission;
use App\Dossiers\Enums\UploadedDocumentPermission;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Lean Inertia prop bag for the dossier review queue page.
 */
final readonly class FetchDossierReviewQuery
{
    public function __construct(
        private MapStaffDocumentRequestRowsQuery $mapStaffDocumentRequestRowsQuery,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, Dossier $dossier): array
    {
        return [
            'can_manage' => $request->user()?->can('create', DocumentRequest::class) ?? false,
            'can_edit' => $request->user()?->can('update', $dossier) ?? false,
            'can_send_reminder' => $request->user()?->can('sendReminder', $dossier) ?? false,
            'can_review' => $request->user()?->hasTenantPermission(DocumentRequestPermission::Review) ?? false,
            'can_download' => $request->user()?->hasTenantPermission(UploadedDocumentPermission::Download) ?? false,
            // Lazy so partial reloads skip the review queue when only cans change.
            'dossier' => fn (): array => $this->dossierPayload($dossier)->toArray(),
        ];
    }

    private function dossierPayload(Dossier $dossier): DossierReviewData
    {
        $dossier->load([
            'client:id,name,email',
            'documentRequests' => fn ($query) => $query
                ->with(['uploadedDocuments', 'reviewedBy:id,name'])
                ->with('uploadedDocuments.latestPipeline.steps')
                ->oldest('sort_order'),
        ]);

        $client = $this->resolveClient($dossier);
        $mapped = $this->mapStaffDocumentRequestRowsQuery->handle(
            $dossier,
            $dossier->documentRequests,
        );

        return new DossierReviewData(
            id: $dossier->id,
            title: $dossier->title,
            status: $dossier->status,
            client: new DossierClientSummaryData(
                name: $client->name,
                email: $client->email,
            ),
            documentRequests: $mapped->rows,
            reviewSummary: $mapped->reviewSummary,
            readyToComplete: $mapped->readyToComplete,
        );
    }

    private function resolveClient(Dossier $dossier): Client
    {
        $client = $dossier->client;

        throw_unless($client instanceof Client, RuntimeException::class, 'Dossier client is missing.');

        return $client;
    }
}
