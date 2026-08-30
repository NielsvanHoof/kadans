<?php

declare(strict_types=1);

namespace App\Integrations\Http\Connectors\Google\Requests\Calendar;

use App\Integrations\Data\Google\CalendarEventResourceData;
use Override;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;

/**
 * Fetch one calendar event (needed before patching attachments).
 */
final class GetEventRequest extends Request
{
    #[Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $eventId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/calendar/v3/calendars/primary/events/'.$this->eventId;
    }

    public function createDtoFromResponse(Response $response): CalendarEventResourceData
    {
        return CalendarEventResourceData::fromResponse($response);
    }
}
