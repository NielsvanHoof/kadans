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
use App\Shared\Data\PersonOptionData;
use Illuminate\Http\Request;
use Inertia\Inertia;
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
        $canDownload = $request->user()?->can('export', $dossier) ?? false;

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
            // Core overview first; exports + audio load after paint (below the fold).
            'dossier' => fn (): array => $this->dossierPayload($dossier)->toArray(),
            'exports' => Inertia::defer(
                fn (): array => $this->exportRows($dossier, $canDownload),
            ),
            'audio_recordings' => Inertia::defer(
                fn (): array => $this->audioRecordingRows($dossier),
            ),
        ];
    }

    private function dossierPayload(Dossier $dossier): DossierShowData
    {
        $dossier->load([
            'client:id,name,email',
            'responsibleUser:id,name,email',
            'documentRequests' => fn ($query) => $query
                ->with(['uploadedDocuments', 'reviewedBy:id,name'])
                ->with('uploadedDocuments.pipelines.steps')
                ->oldest('sort_order'),
            'clientAccessGrants' => fn ($query) => $query->latest(),
        ]);

        // Counted, not loaded: the rows themselves arrive as deferred props, but
        // the tab strip needs both totals on first paint so labels and tab
        // visibility never shift once those props land.
        $dossier->loadCount(['exports', 'audioRecordings']);

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
            // Signed here, not in the browser: calendar days are deliberately not
            // zone-converted, and recomputing client-side reintroduces the drift
            // (same contract as FetchDashboardMyWorkQuery's agenda rows).
            daysRemaining: $dossier->due_date === null
                ? null
                : (int) today()->diffInDays($dossier->due_date, false),
            hasOutstandingClientItems: $mapped->hasOutstandingClientItems,
            readyToComplete: $mapped->readyToComplete,
            reviewSummary: $mapped->reviewSummary,
            client: new DossierClientSummaryData(
                name: $client->name,
                email: $client->email,
            ),
            documentRequests: $mapped->rows,
            accessGrants: $accessGrants,
            // Filled by deferred top-level props after first paint.
            exports: [],
            audioRecordings: [],
            exportsCount: $dossier->exports_count ?? 0,
            audioRecordingsCount: $dossier->audio_recordings_count ?? 0,
        );
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function exportRows(Dossier $dossier, bool $canDownload): array
    {
        $dossier->load([
            'exports' => fn ($query) => $query->with('pipeline.steps')->latest(),
        ]);

        // Built by append rather than map()->all(): Collection::all() is typed
        // array<int, ...>, which PHPStan will not accept as a list.
        $rows = [];

        foreach ($dossier->exports as $export) {
            $rows[] = DossierExportData::fromModel($export, $canDownload)->toArray();
        }

        return $rows;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function audioRecordingRows(Dossier $dossier): array
    {
        $dossier->load([
            'audioRecordings' => fn ($query) => $query->with('pipelines.steps')->latest(),
        ]);

        $rows = [];

        foreach ($dossier->audioRecordings as $recording) {
            $rows[] = StaffAudioRecordingData::fromModel($recording)->toArray();
        }

        return $rows;
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
