<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\AudioRecordings;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Support\DossierLocks;
use App\Pipelines\Actions\StartPipelineAction;
use App\Pipelines\Enums\PipelineType;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

use function is_int;
use function is_string;
use function sprintf;

final readonly class UploadAudioRecordingAction
{
    public function __construct(
        private FilesystemManager $filesystems,
        private StartPipelineAction $startPipeline,
        private LogAuditActivityAction $logAuditActivity,
        private DossierLocks $dossierLocks,
    ) {}

    public function handle(
        Dossier $dossier,
        UploadedFile $file,
        ?User $uploadedBy = null,
    ): AudioRecording {
        $disk = Config::string('filesystems.default', 'local');
        $directory = sprintf('tenants/%d/dossiers/%d/audio', $dossier->tenant_id, $dossier->id);

        $path = $this->filesystems
            ->disk($disk)
            ->putFile($directory, $file);

        throw_if(! is_string($path) || $path === '', RuntimeException::class, 'Failed to store the uploaded audio recording.');

        $sizeBytes = $file->getSize();

        try {
            $audioRecording = DB::transaction(function () use (
                $dossier,
                $disk,
                $path,
                $file,
                $sizeBytes,
                $uploadedBy,
            ): AudioRecording {
                $lockedDossier = $this->dossierLocks->dossier($dossier);

                $audioRecording = $lockedDossier->audioRecordings()->create([
                    'disk' => $disk,
                    'path' => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size_bytes' => is_int($sizeBytes) ? $sizeBytes : 0,
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
