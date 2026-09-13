<?php

declare(strict_types=1);

namespace App\Platform\Actions\DataSubject;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Platform\Enums\DataSubjectRequestStatus;
use App\Platform\Enums\DataSubjectRequestType;
use App\Platform\Events\DataSubject\DataSubjectExportCompleted;
use App\Platform\Models\DataSubjectRequest;
use App\Platform\Transitions\DataSubject\DataSubjectRequestTransitions;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

use function sprintf;

/**
 * Assemble everything the firm holds about one client into a single archive.
 *
 * Mirrors GenerateDossierExportAction's shape — build to a temporary file,
 * stream it onto the configured disk, record an expiring artefact — but is not
 * tracked as a Pipeline. Pipelines exist to show clients and staff progress on
 * OCR, transcription and dossier exports; this is a rare back-office compliance
 * task whose only audience is the operator who logged the request. A failed job
 * leaves the request visibly Pending and overdue, and FailedQueueJobsCheck
 * alerts on the exhausted job.
 */
final readonly class GenerateDataSubjectExportAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private DataSubjectRequestTransitions $transitions,
    ) {}

    /**
     * $staleClaimsBefore is the caller's own worst-case lifecycle: a claim older
     * than that belonged to a worker that is certainly gone.
     */
    public function handle(DataSubjectRequest $request, CarbonInterface $staleClaimsBefore): void
    {
        throw_unless(
            $request->type === DataSubjectRequestType::Export,
            RuntimeException::class,
            'Only an export request produces an archive.',
        );

        // The claim, not a read-only status check. Redis re-reserves a job once
        // retry_after (660s) elapses, which ShouldBeUnique does not prevent —
        // it only blocks new dispatches — and this job's own timeout is 600s
        // against a 630s worker timeout, so the margin is 30s on a run that is
        // entirely stream-bound. Two builds would otherwise both pass a bare
        // isOpen() and spend ten minutes each re-reading the same objects.
        if (! $this->transitions->claimForFulfilment($request, $staleClaimsBefore)) {
            return;
        }

        try {
            $this->buildAndRecord($request);
        } catch (Throwable $exception) {
            // First, before any cleanup that could throw over it. Everything
            // after the claim is inside this try for the same reason: a release
            // reachable only from one inner catch is a release that a
            // misconfigured disk or a vanished client can skip, and a skipped
            // release means the operator's retry silently no-ops until the
            // stale window passes.
            $this->transitions->releaseFulfilmentClaim($request);

            throw $exception;
        }
    }

    /**
     * Everything that happens once the request is ours. Separated from handle()
     * so the claim's release covers all of it, not just the storage writes.
     */
    private function buildAndRecord(DataSubjectRequest $request): void
    {
        $client = Client::withTrashed()->findOrFail($request->client_id);

        $disk = Config::string('exports.disk');
        $archivePath = sprintf(
            'data-subject-exports/%d/%d/%d.zip',
            $request->tenant_id,
            $request->client_id,
            $request->id,
        );
        $temporaryPath = Storage::disk('local')->path(
            'data-subject-export-tmp-'.bin2hex(random_bytes(16)).'.zip',
        );

        // Source files are staged here so ZipArchive can read them lazily at
        // close(). One directory means one cleanup, wherever the build threw.
        $stagingDirectory = 'data-subject-export-staging-'.bin2hex(random_bytes(16));

        try {
            $this->buildArchive($temporaryPath, $request, $client, $stagingDirectory);

            $stream = fopen($temporaryPath, 'rb');

            throw_if($stream === false, RuntimeException::class, 'Unable to read the data-subject export archive.');

            $stored = Storage::disk($disk)->put($archivePath, $stream);
            fclose($stream);

            throw_if($stored === false, RuntimeException::class, 'Unable to store the data-subject export archive.');

            $completedAt = now();
            $expiresAt = $completedAt->copy()->addHours(
                Config::integer('retention.data_subject_export_hours'),
            );

            $request->forceFill([
                'status' => DataSubjectRequestStatus::Completed,
                'completed_at' => $completedAt,
                'disk' => $disk,
                'path' => $archivePath,
                'expires_at' => $expiresAt,
            ])->save();

            $this->logAuditActivity->handle(
                AuditEvent::DataSubjectExportCompleted,
                $request,
                [
                    'client_id' => $request->client_id,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
                includeRequestContext: false,
            );

            // Live-reload the compliance list so the Download button appears
            // without a manual refresh (same channel as pipeline.updated).
            $this->broadcastCompleted($request);
        } catch (Throwable $exception) {
            if (Storage::disk($disk)->exists($archivePath)) {
                Storage::disk($disk)->delete($archivePath);
            }

            throw $exception;
        } finally {
            Storage::disk('local')->deleteDirectory($stagingDirectory);

            if (file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function broadcastCompleted(DataSubjectRequest $request): void
    {
        $request->loadMissing('tenant');

        $tenant = $request->tenant;

        if (! $tenant instanceof Tenant) {
            return;
        }

        event(new DataSubjectExportCompleted($tenant->slug, $request->id));
    }

    private function buildArchive(
        string $temporaryPath,
        DataSubjectRequest $request,
        Client $client,
        string $stagingDirectory,
    ): void {
        $archive = new ZipArchive();

        throw_if(
            $archive->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true,
            RuntimeException::class,
            'Unable to open the data-subject export archive.',
        );

        try {
            $manifest = [
                'request' => [
                    'id' => $request->id,
                    'type' => $request->type->value,
                    'requested_at' => $request->requested_at->toIso8601String(),
                    'generated_at' => now()->toIso8601String(),
                ],
                'client' => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'email' => $client->email,
                    'created_at' => $client->created_at?->toIso8601String(),
                    'archived_at' => $client->deleted_at?->toIso8601String(),
                ],
                'dossiers' => $this->dossiers($archive, $client, $stagingDirectory),
                'portal_access' => $this->portalAccess($client),
                'activity' => $this->activity($client),
            ];

            $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            throw_if(
                ! $archive->addFromString('data.json', $encoded),
                RuntimeException::class,
                'Unable to write the data-subject export manifest.',
            );
        } catch (Throwable $exception) {
            $archive->close();

            throw $exception;
        }

        throw_if(! $archive->close(), RuntimeException::class, 'Unable to finalize the data-subject export archive.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dossiers(ZipArchive $archive, Client $client, string $stagingDirectory): array
    {
        $fileNumber = 0;

        $dossiers = Dossier::withTrashed()
            ->where('client_id', $client->id)
            ->with([
                'documentRequests.uploadedDocuments',
                'audioRecordings',
            ])
            ->get();

        $exported = [];

        foreach ($dossiers as $dossier) {
            $exported[] = [
                'id' => $dossier->id,
                'title' => $dossier->title,
                'reference' => $dossier->reference,
                'status' => $dossier->status->value,
                'due_date' => $dossier->due_date?->toDateString(),
                'created_at' => $dossier->created_at?->toIso8601String(),
                'archived_at' => $dossier->deleted_at?->toIso8601String(),
                'document_requests' => $this->documentRequests($archive, $dossier, $fileNumber, $stagingDirectory),
                'audio_recordings' => $this->audioRecordings($archive, $dossier, $fileNumber, $stagingDirectory),
            ];
        }

        return $exported;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documentRequests(ZipArchive $archive, Dossier $dossier, int &$fileNumber, string $stagingDirectory): array
    {
        $requests = [];

        foreach ($dossier->documentRequests as $documentRequest) {
            $requests[] = [
                'id' => $documentRequest->id,
                'type' => $documentRequest->type->value,
                'title' => $documentRequest->title,
                'instructions' => $documentRequest->instructions,
                'status' => $documentRequest->status->value,
                'answer_text' => $documentRequest->answer_text,
                'answer_boolean' => $documentRequest->answer_boolean,
                'answered_at' => $documentRequest->answered_at?->toIso8601String(),
                'rejection_reason' => $documentRequest->rejection_reason,
                'files' => $this->uploadedDocuments($archive, $documentRequest, $fileNumber, $stagingDirectory),
            ];
        }

        return $requests;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function uploadedDocuments(ZipArchive $archive, DocumentRequest $documentRequest, int &$fileNumber, string $stagingDirectory): array
    {
        $files = [];

        foreach ($documentRequest->uploadedDocuments as $uploadedDocument) {
            $fileNumber++;

            $files[] = [
                'archive_path' => $this->addFile(
                    $archive,
                    'documents',
                    $fileNumber,
                    $uploadedDocument->original_filename,
                    $uploadedDocument->disk,
                    $uploadedDocument->path,
                    $stagingDirectory,
                ),
                'original_filename' => $uploadedDocument->original_filename,
                'mime_type' => $uploadedDocument->mime_type,
                'size_bytes' => $uploadedDocument->size_bytes,
                'uploaded_at' => $uploadedDocument->uploaded_at->toIso8601String(),
                // Search derivatives of extracted_text. The zip already holds
                // the source JSON; do not also ship searchable_text or the
                // Titan embedding vector.
                'extracted_text' => $uploadedDocument->extracted_text,
            ];
        }

        return $files;
    }

    /**
     * Includes the recording itself: a client's voice is their personal data as
     * much as the transcript derived from it.
     *
     * @return list<array<string, mixed>>
     */
    private function audioRecordings(ZipArchive $archive, Dossier $dossier, int &$fileNumber, string $stagingDirectory): array
    {
        $recordings = [];

        foreach ($dossier->audioRecordings as $recording) {
            $fileNumber++;

            $recordings[] = [
                'archive_path' => $this->addFile(
                    $archive,
                    'audio',
                    $fileNumber,
                    $recording->original_filename,
                    $recording->disk,
                    $recording->path,
                    $stagingDirectory,
                ),
                'original_filename' => $recording->original_filename,
                'mime_type' => $recording->mime_type,
                'size_bytes' => $recording->size_bytes,
                'uploaded_at' => $recording->uploaded_at->toIso8601String(),
                'transcript' => $recording->transcript,
                'segments' => $recording->segments,
            ];
        }

        return $recordings;
    }

    /**
     * Grants without their tokens. The token is a live credential and hashed at
     * rest; handing it to the subject would be handing out access, not data.
     *
     * @return list<array<string, mixed>>
     */
    private function portalAccess(Client $client): array
    {
        $dossierIds = Dossier::withTrashed()
            ->where('client_id', $client->id)
            ->toBase()
            ->pluck('id');

        $grantQuery = ClientAccessGrant::query();
        $grantQuery->getQuery()->whereIn('dossier_id', $dossierIds);

        $grants = [];

        foreach ($grantQuery->get() as $grant) {
            $grants[] = [
                'dossier_id' => $grant->dossier_id,
                'created_at' => $grant->created_at?->toIso8601String(),
                'expires_at' => $grant->expires_at->toIso8601String(),
                'revoked_at' => $grant->revoked_at?->toIso8601String(),
                'last_used_at' => $grant->last_used_at?->toIso8601String(),
                'opened_from_new_device_at' => $grant->new_device_seen_at?->toIso8601String(),
            ];
        }

        return $grants;
    }

    /**
     * The trail for the client and its dossiers. Deliberately not the whole
     * subtree: per-document rows add volume without telling the subject anything
     * the dossier-level entries do not.
     *
     * @return list<array<string, mixed>>
     */
    private function activity(Client $client): array
    {
        $dossierIds = Dossier::withTrashed()
            ->where('client_id', $client->id)
            ->toBase()
            ->pluck('id')
            ->all();

        $query = Activity::queryAcrossTenants();
        $query->getQuery()
            ->where(function (QueryBuilder $builder) use ($client, $dossierIds): void {
                $builder
                    ->where(function (QueryBuilder $inner) use ($client): void {
                        $inner->where('subject_type', $client->getMorphClass())
                            ->where('subject_id', $client->id);
                    })
                    ->orWhere(function (QueryBuilder $inner) use ($dossierIds): void {
                        $inner->where('subject_type', (new Dossier)->getMorphClass())
                            ->whereIn('subject_id', $dossierIds);
                    });
            });

        $entries = [];

        foreach ($query->get() as $activity) {
            $entries[] = [
                'event' => $activity->event,
                'description' => $activity->description,
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id,
                'occurred_at' => $activity->created_at?->toIso8601String(),
            ];
        }

        return $entries;
    }

    /**
     * Copy a stored object to local disk and return its real path.
     *
     * The archive used to be built with addFromString(), which makes
     * ZipArchive hold every file's bytes until close(). This export can
     * contain every document AND every audio recording ever held about one
     * person, so it was the worse of the two for peak memory — on a queue
     * worker with no memory hint. addFile() reads lazily at close() instead,
     * and the copy streams, so peak memory is one stream buffer.
     */
    private function stageForArchive(string $sourceDisk, string $sourcePath, string $stagedPath): string
    {
        $source = Storage::disk($sourceDisk)->readStream($sourcePath);

        throw_if(
            $source === null,
            RuntimeException::class,
            'Unable to read a stored file for the data-subject export.',
        );

        try {
            $staged = Storage::disk('local')->writeStream($stagedPath, $source);
        } finally {
            fclose($source);
        }

        throw_if(
            ! $staged,
            RuntimeException::class,
            'Unable to stage a stored file for the data-subject export.',
        );

        return Storage::disk('local')->path($stagedPath);
    }

    /**
     * Numbered so two uploads with the same original filename cannot collide.
     */
    private function addFile(
        ZipArchive $archive,
        string $folder,
        int $number,
        string $originalFilename,
        string $disk,
        string $path,
        string $stagingDirectory,
    ): string {
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $basename = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME));

        $archivePath = sprintf(
            '%s/%03d-%s%s',
            $folder,
            $number,
            $basename !== '' ? $basename : 'file',
            $extension !== '' ? '.'.mb_strtolower($extension) : '',
        );

        throw_if(
            ! $archive->addFile(
                $this->stageForArchive($disk, $path, $stagingDirectory.'/'.$number),
                $archivePath,
            ),
            RuntimeException::class,
            'Unable to add a stored file to the data-subject export.',
        );

        return $archivePath;
    }
}
