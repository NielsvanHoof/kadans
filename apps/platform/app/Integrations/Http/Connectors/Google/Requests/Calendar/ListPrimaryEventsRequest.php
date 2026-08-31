<?php

declare(strict_types=1);

namespace App\Integrations\Http\Connectors\Google\Requests\Calendar;

use App\Integrations\Data\Google\CalendarEventsListData;
use Carbon\CarbonInterface;
use Override;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;

/**
 * List primary-calendar events in a time window (expanded recurring instances).
 */
final class ListPrimaryEventsRequest extends Request
{
    #[Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly CarbonInterface $timeMin,
        private readonly CarbonInterface $timeMax,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/calendar/v3/calendars/primary/events';
    }

    public function createDtoFromResponse(Response $response): CalendarEventsListData
    {
        return CalendarEventsListData::fromResponse($response);
    }

    /**
     * Google rejects PHP/Guzzle's `1` encoding of bools — use the literal "true"/"false".
     *
     * @return array<string, string|int>
     */
    protected function defaultQuery(): array
    {
        return [
            'timeMin' => $this->timeMin->toIso8601String(),
            'timeMax' => $this->timeMax->toIso8601String(),
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => 50,
        ];
    }
}
