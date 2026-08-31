<?php

declare(strict_types=1);

namespace App\Integrations\Http\Connectors\Google\Requests\Drive;

use App\Integrations\Data\Google\DriveFileData;
use Override;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Set Drive file display name after a media upload.
 */
final class UpdateDriveFileMetadataRequest extends Request implements HasBody
{
    use HasJsonBody;

    #[Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly string $fileId,
        private readonly string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/drive/v3/files/'.$this->fileId;
    }

    public function createDtoFromResponse(Response $response): DriveFileData
    {
        return DriveFileData::fromResponse($response);
    }

    /**
     * @return array{fields: string}
     */
    protected function defaultQuery(): array
    {
        return [
            'fields' => 'id,name,webViewLink,mimeType',
        ];
    }

    /**
     * @return array{name: string}
     */
    protected function defaultBody(): array
    {
        return [
            'name' => $this->name,
        ];
    }
}
