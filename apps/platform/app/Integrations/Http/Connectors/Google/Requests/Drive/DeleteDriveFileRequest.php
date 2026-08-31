<?php

declare(strict_types=1);

namespace App\Integrations\Http\Connectors\Google\Requests\Drive;

use Override;
use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Permanently delete a Drive file (empty 204 — no DTO).
 */
final class DeleteDriveFileRequest extends Request
{
    #[Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly string $fileId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/drive/v3/files/'.$this->fileId;
    }
}
