<?php

declare(strict_types=1);

namespace App\Integrations\Data\Google;

use Saloon\Http\Response;

use function is_array;

/**
 * Google Calendar events.list payload (items only).
 */
final readonly class CalendarEventsListData
{
    /**
     * @param  list<CalendarEventItemData>  $items
     */
    public function __construct(
        public array $items,
    ) {}

    public static function fromResponse(Response $response): self
    {
        /** @var array{items?: mixed} $payload */
        $payload = $response->json();
        $rawItems = $payload['items'] ?? [];

        if (! is_array($rawItems)) {
            return new self([]);
        }

        $items = [];

        foreach ($rawItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $mapped = CalendarEventItemData::fromArray($item);

            if ($mapped instanceof CalendarEventItemData) {
                $items[] = $mapped;
            }
        }

        return new self($items);
    }
}
