<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\AudioRecordings;

use App\Accounts\Models\User;
use App\AudioProcessing\Jobs\ProcessAudioRecordingJob;
use App\DocumentProcessing\Actions\Pipelines\CreatePipelineAction;
use App\DocumentProcessing\Enums\PipelineType;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\DocumentProcessing\Models\Pipeline;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

use function is_int;
use function is_string;
use function sprintf;

final readonly class UploadAudioRecordingAction
{
    public function __construct(
        private FilesystemManager $filesystems,
        private CreatePipelineAction $createPipeline,
    ) {}

    public function handle(
        Dossier $dossier,
        UploadedFile $file,
        ?User $uploadedBy = null,
    ): AudioRecording {
        $disk = (string) config('filesystems.default', 'local');
        $directory = sprintf('tenants/%d/dossiers/%d/audio', $dossier->tenant_id, $dossier->id);

        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
        $path = $this->filesystems
            ->disk($disk)
            ->putFileAs($directory, $file, $filename);

        throw_if(! is_string($path) || $path === '', RuntimeException::class, 'Failed to store the uploaded audio recording.');

        $sizeBytes = $file->getSize();
        $pipeline = null;

        try {
            $audioRecording = DB::transaction(function () use (
                $dossier,
                $disk,
                $path,
                $file,
                $sizeBytes,
                $uploadedBy,
                &$pipeline,
            ): AudioRecording {
                $dossierQuery = Dossier::query()->whereKey($dossier->getKey());
                $dossierQuery->getQuery()->lockForUpdate();
                $lockedDossier = $dossierQuery->firstOrFail();

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

                $pipeline = $this->createPipeline->handle(
                    $audioRecording,
                    PipelineType::AudioTranscription,
                    $uploadedBy,
                    metadata: [
                        'original_filename' => $audioRecording->original_filename,
                    ],
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

        throw_if(! $pipeline instanceof Pipeline, RuntimeException::class, 'Failed to create the audio transcription pipeline.');

        dispatch(new ProcessAudioRecordingJob($audioRecording->id, $audioRecording->path, $pipeline->id));

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
