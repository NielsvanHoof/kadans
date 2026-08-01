<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

use App\DocumentProcessing\Data\PipelineData;
use App\Dossiers\Enums\DossierExportStatus;
use App\Dossiers\Models\DossierExport;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DossierExportData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly DossierExportStatus $status,
        public readonly string $createdAt,
        public readonly ?string $expiresAt,
        public readonly ?string $downloadUrl,
        public readonly ?string $error,
        public readonly ?PipelineData $pipeline,
    ) {}

    public static function fromModel(DossierExport $export, bool $canDownload): self
    {
        $expiresAt = $export->expires_at;
        $downloadUrl = null;

        if (
            $canDownload
            && $export->status === DossierExportStatus::Completed
            && $expiresAt?->isFuture() === true
        ) {
            $tenant = $export->tenant;

            throw_unless($tenant instanceof Tenant, RuntimeException::class, 'Dossier export tenant is missing.');

            $downloadUrl = URL::temporarySignedRoute(
                'workspaces.dossiers.exports.download',
                $expiresAt,
                [
                    'tenant' => $tenant->slug,
                    'dossier' => $export->dossier_id,
                    'export' => $export->id,
                ],
            );
        }

        return new self(
            id: $export->id,
            status: $export->status,
            createdAt: $export->created_at?->format(DATE_ATOM) ?? '',
            expiresAt: $expiresAt?->format(DATE_ATOM),
            downloadUrl: $downloadUrl,
            error: $export->error,
            pipeline: $export->relationLoaded('pipeline') && $export->pipeline !== null
                ? PipelineData::fromModel($export->pipeline)
                : null,
        );
    }
}
