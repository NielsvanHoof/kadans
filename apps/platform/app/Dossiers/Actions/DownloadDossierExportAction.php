<?php

declare(strict_types=1);

namespace App\Dossiers\Actions;

use App\Dossiers\Models\DossierExport;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a completed export archive as an attachment.
 */
final class DownloadDossierExportAction
{
    public function handle(DossierExport $export): StreamedResponse
    {
        $disk = $export->disk;
        $path = $export->path;

        abort_if(! $export->isDownloadable() || $disk === null || $path === null, HttpResponse::HTTP_NOT_FOUND);

        return Storage::disk($disk)->download(
            $path,
            $this->filename($export),
            ['Content-Type' => 'application/zip'],
        );
    }

    /**
     * Archive filename derived from the dossier title, e.g. `acme-payroll-export.zip`.
     */
    private function filename(DossierExport $export): string
    {
        $export->loadMissing('dossier');

        $slug = Str::slug((string) $export->dossier?->title);

        return ($slug !== '' ? $slug : 'dossier').'-export.zip';
    }
}
