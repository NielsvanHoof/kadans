<?php

declare(strict_types=1);

namespace App\Integrations\Data\Google;

use function is_array;
use function is_string;

/**
 * One Google Calendar events.list item (fields we map into product CalendarEventData).
 */
final readonly class CalendarEventItemData
{
    /**
     * @param  list<string>  $attendeeEmails
     */
    public function __construct(
        public string $id,
        public ?string $summary,
        public ?string $htmlLink,
        public ?string $startsAt,
        public ?string $endsAt,
        public array $attendeeEmails,
    ) {}

    /**
     * @param  array<mixed>  $item
     */
    public static function fromArray(array $item): ?self
    {
        $id = $item['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $attendeeEmails = [];
        $attendees = $item['attendees'] ?? [];

        if (is_array($attendees)) {
            foreach ($attendees as $attendee) {
                if (! is_array($attendee)) {
                    continue;
                }

                $email = $attendee['email'] ?? null;

                if (is_string($email) && $email !== '') {
                    $attendeeEmails[] = mb_strtolower(mb_trim($email));
                }
            }
        }

        $summary = $item['summary'] ?? null;
        $htmlLink = $item['htmlLink'] ?? null;
        $start = is_array($item['start'] ?? null) ? $item['start'] : [];
        $end = is_array($item['end'] ?? null) ? $item['end'] : [];

        return new self(
            id: $id,
            summary: is_string($summary) && $summary !== '' ? $summary : null,
            htmlLink: is_string($htmlLink) && $htmlLink !== '' ? $htmlLink : null,
            startsAt: self::eventDateTime($start),
            endsAt: self::eventDateTime($end),
            attendeeEmails: $attendeeEmails,
        );
    }

    /**
     * @param  array<mixed>  $dateTime
     */
    private static function eventDateTime(array $dateTime): ?string
    {
        $dateTimeValue = $dateTime['dateTime'] ?? null;

        if (is_string($dateTimeValue) && $dateTimeValue !== '') {
            return $dateTimeValue;
        }

        $dateValue = $dateTime['date'] ?? null;

        return is_string($dateValue) && $dateValue !== '' ? $dateValue : null;
    }
}
