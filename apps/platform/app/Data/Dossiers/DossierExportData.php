<?php

declare(strict_types=1);

namespace App\Data\Dossiers;

use App\Enums\DossierExportStatus;
use App\Models\DossierExport;
use App\Models\Tenant;
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
        );
    }

    /**
     * @return array{id: int, status: string, created_at: string, expires_at: string|null, download_url: string|null, error: string|null}
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
        ];
    }
}
