<?php

declare(strict_types=1);

namespace App\Integrations\Http\Connectors\Google\Requests\Drive;

use App\Integrations\Data\Google\DriveFileData;
use Override;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;

/**
 * Create a Drive file from raw text (uploadType=media). Name is set in a follow-up.
 */
final class UploadPlainTextMediaRequest extends Request implements HasBody
{
    use HasStringBody;

    #[Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $contents,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/upload/drive/v3/files';
    }

    public function createDtoFromResponse(Response $response): DriveFileData
    {
        return DriveFileData::fromResponse($response);
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'uploadType' => 'media',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ];
    }

    protected function defaultBody(): string
    {
        return $this->contents;
    }
}
