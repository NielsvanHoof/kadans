<?php

declare(strict_types=1);

namespace App\Integrations\Data\Google;

use RuntimeException;
use Saloon\Http\Response;

use function is_string;

/**
 * Google Drive file resource fields we actually use (upload + metadata).
 */
final readonly class DriveFileData
{
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?string $webViewLink = null,
        public ?string $mimeType = null,
    ) {}

    public static function fromResponse(Response $response): self
    {
        /** @var array<string, mixed> $data */
        $data = $response->json();

        $id = $data['id'] ?? null;

        throw_if(! is_string($id) || $id === '', RuntimeException::class, 'Google Drive response did not include a file id.');

        $name = $data['name'] ?? null;
        $webViewLink = $data['webViewLink'] ?? null;
        $mimeType = $data['mimeType'] ?? null;

        return new self(
            id: $id,
            name: is_string($name) && $name !== '' ? $name : null,
            webViewLink: is_string($webViewLink) && $webViewLink !== '' ? $webViewLink : null,
            mimeType: is_string($mimeType) && $mimeType !== '' ? $mimeType : null,
        );
    }
}
