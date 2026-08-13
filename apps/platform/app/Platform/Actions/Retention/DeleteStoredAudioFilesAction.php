<?php

declare(strict_types=1);

namespace App\Platform\Actions\Retention;

use App\Dossiers\Models\AudioRecording;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

final readonly class DeleteStoredAudioFilesAction
{
    public function __construct(private FilesystemManager $filesystems) {}

    /**
     * Remove audio blobs after the dossier row (and its cascade) has committed.
     *
     * Disk and path are required columns, so every collected recording is a
     * real object. A shared deleter with documents would couple
     * DocumentProcessing to AudioProcessing — that is forbidden.
     *
     * @param  Collection<int, AudioRecording>  $audioRecordings
     */
    public function handle(Collection $audioRecordings): void
    {
        foreach ($audioRecordings as $audioRecording) {
            try {
                $deleted = $this->filesystems
                    ->disk($audioRecording->disk)
                    ->delete($audioRecording->path);
            } catch (Throwable $exception) {
                report(new RuntimeException(
                    'Failed to remove a stored audio recording during legal retention purge.',
                    previous: $exception,
                ));

                throw $exception;
            }

            if (! $deleted) {
                report(new RuntimeException(
                    'Failed to remove a stored audio recording during legal retention purge.',
                ));

                throw new RuntimeException(
                    'Failed to remove a stored audio recording during legal retention purge.',
                );
            }
        }
    }
}
