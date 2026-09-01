<?php

declare(strict_types=1);

namespace App\Dossiers\Actions;

use App\Dossiers\Models\DossierExport;
use App\Dossiers\Support\StoredFileResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Serves a completed export archive as an attachment.
 *
 * An export bundles every document on a dossier, so it is the largest object
 * this application hands back. On S3 it is delivered as a short-lived signed
 * URL rather than streamed, so one download does not hold an Octane worker for
 * the length of the transfer — the same treatment the individual documents
 * inside the archive already get.
 *
 * Note the deliberate asymmetry with DataSubjectRequestController::download(),
 * which keeps streaming: that archive is everything the firm holds about one
 * person, and its docblock records why it must not become a link that outlives
 * the session it was issued in.
 */
final readonly class DownloadDossierExportAction
{
    public function __construct(private StoredFileResponse $storedFileResponse) {}

    public function handle(DossierExport $export): HttpResponse
    {
        $disk = $export->disk;
        $path = $export->path;

        abort_if(! $export->isDownloadable() || $disk === null || $path === null, HttpResponse::HTTP_NOT_FOUND);

        return $this->storedFileResponse->serve(
            $disk,
            $path,
            $this->filename($export),
            'application/zip',
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
