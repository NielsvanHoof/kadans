<?php

declare(strict_types=1);

namespace App\Integrations\Http\Connectors\Google\Requests\Calendar;

use App\Integrations\Data\Google\CalendarEventResourceData;
use Override;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Patch event attachments (Drive file). Requires supportsAttachments=true.
 *
 * @phpstan-type AttachmentShape array{fileUrl: string, mimeType: string, title: string}
 */
final class PatchEventAttachmentsRequest extends Request implements HasBody
{
    use HasJsonBody;

    #[Override]
    protected Method $method = Method::PATCH;

    /**
     * @param  list<AttachmentShape>  $attachments
     */
    public function __construct(
        private readonly string $eventId,
        private readonly array $attachments,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/calendar/v3/calendars/primary/events/'.$this->eventId;
    }

    public function createDtoFromResponse(Response $response): CalendarEventResourceData
    {
        return CalendarEventResourceData::fromResponse($response);
    }

    /**
     * Google rejects PHP/Guzzle's `1` encoding of bools — use the literal "true"/"false".
     *
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'supportsAttachments' => 'true',
        ];
    }

    /**
     * @return array{attachments: list<AttachmentShape>}
     */
    protected function defaultBody(): array
    {
        return [
            'attachments' => $this->attachments,
        ];
    }
}
