<?php

declare(strict_types=1);

namespace App\Actions\Retention;

use App\Models\DossierExport;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

final class DeleteStoredDossierExportFilesAction
{
    public function __construct(private readonly FilesystemManager $filesystems) {}

    /**
     * @param  Collection<int, DossierExport>  $exports
     */
    public function handle(Collection $exports): void
    {
        foreach ($exports as $export) {
            if ($export->disk === null || $export->path === null) {
                continue;
            }

            try {
                $deleted = $this->filesystems->disk($export->disk)->delete($export->path);
            } catch (Throwable $exception) {
                report(new RuntimeException(
                    'Failed to remove a stored dossier export during retention cleanup.',
                    previous: $exception,
                ));

                throw $exception;
            }

            if (! $deleted) {
                throw new RuntimeException('Failed to remove a stored dossier export during retention cleanup.');
            }
        }
    }
}
