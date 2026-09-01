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
use App\Dossiers\Data\StaffDocumentRequestData;
use App\Dossiers\Enums\AudioRecordingPermission;
use App\Dossiers\Enums\DocumentRequestPermission;
use App\Dossiers\Enums\UploadedDocumentPermission;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Shared\Data\PersonOptionData;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

use function is_string;

/**
 * Full Inertia prop bag for the dossier overview (show) page.
 */
final readonly class FetchDossierShowQuery
{
    /**
     * Expired/revoked grants shown as history. Valid grants are always
     * included so staff can still revoke them, regardless of age.
     */
    private const int RECENT_ACCESS_GRANT_LIMIT = 20;

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
            // Core overview first; questionnaire rows, exports, and audio load after paint.
            'dossier' => fn (): array => $this->dossierPayload($dossier)->toArray(),
            'document_requests' => Inertia::defer(
                fn (): array => $this->documentRequestRows($dossier),
            ),
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
                ->with('reviewedBy:id,name')
                ->oldest('sort_order'),
        ]);

        // Counted, not loaded: the rows themselves arrive as deferred props, but
        // the tab strip needs both totals on first paint so labels and tab
        // visibility never shift once those props land.
        $dossier->loadCount(['exports', 'audioRecordings']);

        $client = $this->resolveClient($dossier);
        $mapped = $this->mapStaffDocumentRequestRowsQuery->handle(
            $dossier,
            $dossier->documentRequests,
            includeUploadedDocuments: false,
        );

        $responsibleUser = $dossier->responsibleUser;

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
            documentRequests: [],
            accessGrants: $this->accessGrantRows($dossier),
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
    private function documentRequestRows(Dossier $dossier): array
    {
        $dossier->load([
            'documentRequests' => fn ($query) => $query
                ->with(['uploadedDocuments', 'reviewedBy:id,name'])
                ->with('uploadedDocuments.latestPipeline.steps')
                ->oldest('sort_order'),
        ]);

        $mapped = $this->mapStaffDocumentRequestRowsQuery->handle(
            $dossier,
            $dossier->documentRequests,
        );

        return array_map(
            static fn (StaffDocumentRequestData $row): array => $row->toArray(),
            $mapped->rows,
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
            'audioRecordings' => fn ($query) => $query->with('latestPipeline.steps')->latest(),
        ]);

        $dossier->loadMissing('tenant');

        $rows = [];

        foreach ($dossier->audioRecordings as $recording) {
            $recording->setRelation('dossier', $dossier);

            $rows[] = StaffAudioRecordingData::fromModel($recording)->toArray();
        }

        return $rows;
    }

    /**
     * Every currently valid grant (so revoke stays possible) plus the most
     * recent expired/revoked ones. Creating a new portal link every week
     * would otherwise serialize the whole history on first paint.
     *
     * @return list<DossierAccessGrantData>
     */
    private function accessGrantRows(Dossier $dossier): array
    {
        // HasMany::getQuery() is the Eloquent builder; constraints go on its
        // base query. phpstan-strict-rules flags Builder::valid()/latest()/
        // limit()/whereNotNull() as staticMethod.dynamicCall.
        $validQuery = $dossier->clientAccessGrants()->getQuery();
        $validQuery->getQuery()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest();
        $valid = $validQuery->get();

        $historyQuery = $dossier->clientAccessGrants()->getQuery();
        $historyQuery->getQuery()
            ->where(function (Builder $query): void {
                $query->whereNotNull('revoked_at')
                    ->orWhere('expires_at', '<=', now());
            })
            ->latest()
            ->limit(self::RECENT_ACCESS_GRANT_LIMIT);
        $history = $historyQuery->get();

        /** @var list<DossierAccessGrantData> $rows */
        $rows = [];
        $seen = [];

        foreach ($valid->concat($history) as $grant) {
            if (isset($seen[$grant->id])) {
                continue;
            }

            $seen[$grant->id] = true;
            $rows[] = new DossierAccessGrantData(
                id: $grant->id,
                expiresAt: $grant->expires_at->toIso8601String(),
                revokedAt: $grant->revoked_at?->toIso8601String(),
                lastUsedAt: $grant->last_used_at?->toIso8601String(),
                isValid: $grant->isValid(),
            );
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
