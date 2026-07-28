<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

use App\DocumentProcessing\Data\PipelineData;
use App\Dossiers\Enums\DossierExportStatus;
use App\Dossiers\Models\DossierExport;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\URL;
use RuntimeException;

final readonly class DossierExportData
{
    public function __construct(
        public int $id,
        public string $status,
        public string $createdAt,
        public ?string $expiresAt,
        public ?string $downloadUrl,
        public ?string $error,
        public ?PipelineData $pipeline,
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

            if (! $tenant instanceof Tenant) {
                throw new RuntimeException('Dossier export tenant is missing.');
            }

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
            status: $export->status->value,
            createdAt: $export->created_at->format(DATE_ATOM),
            expiresAt: $expiresAt?->format(DATE_ATOM),
            downloadUrl: $downloadUrl,
            error: $export->error,
            pipeline: $export->relationLoaded('pipeline') && $export->pipeline !== null
                ? PipelineData::fromModel($export->pipeline)
                : null,
        );
    }

    /**
     * @return array{id: int, status: string, created_at: string, expires_at: string|null, download_url: string|null, error: string|null, pipeline: array{id: int, type: string, status: string, current_stage: string|null, attempt_number: int, last_activity_at: string|null, steps: list<array{key: string, status: string, attempts: int, started_at: string|null, last_activity_at: string|null, completed_at: string|null, error_code: string|null, error_message: string|null}>}|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
            'download_url' => $this->downloadUrl,
            'error' => $this->error,
            'pipeline' => $this->pipeline?->toArray(),
        ];
    }
}
