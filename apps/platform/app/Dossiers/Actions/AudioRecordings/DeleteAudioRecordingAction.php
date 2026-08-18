<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\AudioRecordings;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Models\AudioRecording;
use App\Pipelines\Actions\DeletePipelinesForSubjectsAction;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Remove a staff-uploaded audio recording, its transcript and its pipeline runs.
 *
 * The inverse of UploadAudioRecordingAction, and modelled on
 * DeleteUploadedDocumentAction, which already had all three properties this
 * path lacked. Previously the controller deleted the object from storage
 * *first*, then the pipelines, then the row, with no transaction and the
 * Storage::delete() return value discarded — so a failure at any later step
 * left a live row pointing at bytes that no longer existed, and a failed
 * delete left an orphan object nobody was told about.
 *
 * Order matters and is the same as every other delete path here: the row goes
 * inside the transaction, the blob only after it commits. Object storage does
 * not roll back, so deleting the blob first means a rollback has already
 * destroyed the file it was trying to protect.
 *
 * An AudioRecordingDeleted audit entry is written inside the transaction.
 * AudioRecording does not use LogsActivity, so before this a deletion left no
 * trace at all — while uploads and downloads of the same file were both
 * recorded. For a firm that must account for what happened to a client's data,
 * a silent deletion is the one event you cannot afford to lose.
 */
final readonly class DeleteAudioRecordingAction
{
    public function __construct(
        private DeletePipelinesForSubjectsAction $deletePipelinesForSubjects,
        private LogAuditActivityAction $logAuditActivity,
        private FilesystemManager $filesystems,
    ) {}

    public function handle(AudioRecording $audioRecording, ?User $deletedBy = null): void
    {
        // Snapshotted before the transaction: the model is deleted inside it.
        $disk = $audioRecording->disk;
        $path = $audioRecording->path;

        DB::transaction(function () use ($audioRecording, $deletedBy): void {
            $this->deletePipelinesForSubjects->handle([$audioRecording]);

            $this->logAuditActivity->handle(
                AuditEvent::AudioRecordingDeleted,
                $audioRecording,
                ['original_filename' => $audioRecording->original_filename],
                $deletedBy,
            );

            $audioRecording->delete();
        });

        $this->deleteFileWithoutMaskingFailure(
            $disk,
            $path,
            'Failed to remove the storage object for a deleted audio recording.',
        );
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
