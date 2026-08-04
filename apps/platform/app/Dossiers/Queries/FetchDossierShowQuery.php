<?php

declare(strict_types=1);

namespace App\Dossiers\Queries;

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Data\DossierAccessGrantData;
use App\Dossiers\Data\DossierClientSummaryData;
use App\Dossiers\Data\DossierExportData;
use App\Dossiers\Data\DossierShowData;
use App\Dossiers\Data\StaffAudioRecordingData;
use App\Dossiers\Enums\AudioRecordingPermission;
use App\Dossiers\Enums\DocumentRequestPermission;
use App\Dossiers\Enums\UploadedDocumentPermission;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Shared\Data\PersonOptionData;
use Illuminate\Http\Request;
use RuntimeException;

use function is_string;

/**
 * Full Inertia prop bag for the dossier overview (show) page.
 */
final readonly class FetchDossierShowQuery
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
            'access_grant_token' => fn (): ?string => $this->flashedAccessGrantToken($request),
            'access_grant_portal_url' => fn (): ?string => $this->flashedAccessGrantPortalUrl($request),
            'can_manage' => $request->user()?->can('create', DocumentRequest::class) ?? false,
            'can_edit' => $request->user()?->can('update', $dossier) ?? false,
            'can_send_reminder' => $request->user()?->can('sendReminder', $dossier) ?? false,
            'can_review' => $request->user()?->hasTenantPermission(DocumentRequestPermission::Review) ?? false,
            'can_download' => $request->user()?->hasTenantPermission(UploadedDocumentPermission::Download) ?? false,
            'can_upload_audio' => $request->user()?->can('create', [AudioRecording::class, $dossier]) ?? false,
            'can_download_audio' => $request->user()?->hasTenantPermission(AudioRecordingPermission::Download) ?? false,
            // Lazy so partial reloads skip the full graph when only flash/cans change.
            'dossier' => fn (): array => $this->dossierPayload(
                $dossier,
                $request->user()?->can('export', $dossier) ?? false,
            )->toArray(),
        ];
    }

    private function dossierPayload(Dossier $dossier, bool $canDownload): DossierShowData
    {
        $dossier->load([
            'client:id,name,email',
            'responsibleUser:id,name,email',
            'documentRequests' => fn ($query) => $query
                ->with(['uploadedDocuments', 'reviewedBy:id,name'])
                ->with('uploadedDocuments.pipelines.steps')
                ->oldest('sort_order'),
            'clientAccessGrants' => fn ($query) => $query->latest(),
            'exports' => fn ($query) => $query->with('pipeline.steps')->latest(),
            'audioRecordings' => fn ($query) => $query->with('pipelines.steps')->latest(),
        ]);

        $client = $this->resolveClient($dossier);
        $mapped = $this->mapStaffDocumentRequestRowsQuery->handle(
            $dossier->status,
            $dossier->documentRequests,
        );

        $responsibleUser = $dossier->responsibleUser;

        /** @var list<DossierAccessGrantData> $accessGrants */
        $accessGrants = [];

        foreach ($dossier->clientAccessGrants as $grant) {
            $accessGrants[] = new DossierAccessGrantData(
                id: $grant->id,
                expiresAt: $grant->expires_at->toIso8601String(),
                revokedAt: $grant->revoked_at?->toIso8601String(),
                lastUsedAt: $grant->last_used_at?->toIso8601String(),
                isValid: $grant->isValid(),
            );
        }

        /** @var list<DossierExportData> $exports */
        $exports = $dossier->exports
            ->map(fn (DossierExport $export): DossierExportData => DossierExportData::fromModel($export, $canDownload))
            ->all();

        /** @var list<StaffAudioRecordingData> $audioRecordings */
        $audioRecordings = $dossier->audioRecordings
            ->map(fn (AudioRecording $recording): StaffAudioRecordingData => StaffAudioRecordingData::fromModel($recording))
            ->all();

        return new DossierShowData(
            id: $dossier->id,
            title: $dossier->title,
            reference: $dossier->reference,
            status: $dossier->status,
            dueDate: $dossier->due_date?->toDateString(),
            responsibleStaff: $responsibleUser instanceof User
                ? new PersonOptionData(
                    id: $responsibleUser->id,
                    name: $responsibleUser->name,
                    email: $responsibleUser->email,
                )
                : null,
            reminderIntervalDays: $dossier->reminder_interval_days,
            nextReminderAt: $dossier->next_reminder_at?->toIso8601String(),
            lastClientMessageSentAt: $dossier->last_client_message_sent_at?->toIso8601String(),
            lastClientOpenedAt: $dossier->last_client_opened_at?->toIso8601String(),
            hasOutstandingClientItems: $mapped->hasOutstandingClientItems,
            readyToComplete: $mapped->readyToComplete,
            reviewSummary: $mapped->reviewSummary,
            client: new DossierClientSummaryData(
                name: $client->name,
                email: $client->email,
            ),
            documentRequests: $mapped->rows,
            accessGrants: $accessGrants,
            exports: $exports,
            audioRecordings: $audioRecordings,
        );
    }

    private function resolveClient(Dossier $dossier): Client
    {
        $client = $dossier->client;

        throw_unless($client instanceof Client, RuntimeException::class, 'Dossier client is missing.');

        return $client;
    }

    private function flashedAccessGrantToken(Request $request): ?string
    {
        $token = $request->session()->pull('access_grant_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function flashedAccessGrantPortalUrl(Request $request): ?string
    {
        $url = $request->session()->pull('access_grant_portal_url');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
