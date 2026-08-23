<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\AudioRecordings;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Support\DocumentPresigner;
use App\Dossiers\Support\DossierLocks;
use App\Dossiers\Support\StagedUpload;
use App\Pipelines\Actions\StartPipelineAction;
use App\Pipelines\Enums\PipelineType;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

use function sprintf;

final readonly class UploadAudioRecordingAction
{
    public function __construct(
        private DocumentPresigner $presigner,
        private FilesystemManager $filesystems,
        private StartPipelineAction $startPipeline,
        private LogAuditActivityAction $logAuditActivity,
        private DossierLocks $dossierLocks,
    ) {}

    /**
     * The recording is already in the bucket when this runs: the browser PUT it
     * to the staging prefix with a presigned URL and ClaimUploadTicketAction
     * verified its real size and content type against the object. All that is
     * left here is a server-side move into the dossier tree and the row.
     */
    public function handle(
        Dossier $dossier,
        StagedUpload $upload,
        ?User $uploadedBy = null,
    ): AudioRecording {
        $disk = $upload->disk;
        $directory = sprintf('tenants/%d/dossiers/%d/audio', $dossier->tenant_id, $dossier->id);

        // The staging key already ends in a ULID, so the basename carries no
        // client-supplied text and cannot collide.
        $path = $directory.'/'.basename($upload->path);

        $this->presigner->moveObject($disk, $upload->path, $path);

        try {
            $audioRecording = DB::transaction(function () use (
                $dossier,
                $disk,
                $path,
                $upload,
                $uploadedBy,
            ): AudioRecording {
                $lockedDossier = $this->dossierLocks->dossier($dossier);

                $audioRecording = $lockedDossier->audioRecordings()->create([
                    'disk' => $disk,
                    'path' => $path,
                    'original_filename' => $upload->originalFilename,
                    'mime_type' => $upload->mimeType,
                    'size_bytes' => $upload->sizeBytes,
                    'uploaded_at' => now(),
                    'uploaded_by' => $uploadedBy?->id,
                    'transcription_status' => TranscriptionStatus::Pending,
                    'transcript' => null,
                    'transcription_error' => null,
                    'transcription_started_at' => null,
                    'transcription_finished_at' => null,
                ]);

                $this->startPipeline->handle(
                    $audioRecording,
                    PipelineType::AudioTranscription,
                    $uploadedBy,
                    metadata: [
                        'original_filename' => $audioRecording->original_filename,
                    ],
                );

                $this->logAuditActivity->handle(
                    AuditEvent::AudioRecordingUploaded,
                    $audioRecording,
                    ['original_filename' => $audioRecording->original_filename],
                    $uploadedBy,
                );

                return $audioRecording;
            });
        } catch (Throwable $exception) {
            $this->deleteFileWithoutMaskingFailure(
                $disk,
                $path,
                'Failed to remove a newly uploaded audio recording after its database transaction rolled back.',
            );

            throw $exception;
        }

        return $audioRecording;
    }

    private function deleteFileWithoutMaskingFailure(
        string $disk,
        string $path,
        string $message,
    ): void {
        try {
            $deleted = $this->filesystems->disk($disk)->delete($path);
        } catch (Throwable $exception) {
            report(new RuntimeException($message, previous: $exception));

            return;
        }

        report_unless($deleted, new RuntimeException($message));
    }
}
