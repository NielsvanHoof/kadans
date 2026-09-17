<?php

declare(strict_types=1);

namespace App\Dossiers\Support\UploadedDocuments;

use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Config;

/**
 * The URL a document's thumbnail is served from, or null when there is none.
 *
 * Small enough to inline at each call site, and deliberately not inlined: three
 * separate conditions have to hold before a preview exists, and each one of
 * them is a way for a list to render a broken image instead of an icon.
 */
final readonly class UploadedDocumentThumbnailUrl
{
    public static function for(UploadedDocument $uploadedDocument): ?string
    {
        /*
         * Off wherever the rendering Lambda does not exist, which is everywhere
         * but a deployed stack. Without this, local and CI would offer a preview
         * for every document and every one of them would 404.
         */
        if (! Config::boolean('scanning.thumbnails.enabled', false)) {
            return null;
        }

        $thumbnailPath = $uploadedDocument->thumbnail_path;

        if ($thumbnailPath === null || $thumbnailPath === '') {
            return null;
        }

        $tenant = Tenant::current();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return route('workspaces.uploaded-documents.thumbnail', [
            'tenant' => $tenant->slug,
            'uploadedDocument' => $uploadedDocument->id,
        ]);
    }
}
