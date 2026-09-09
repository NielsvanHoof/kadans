<?php

declare(strict_types=1);

namespace App\Integrations\Data\Google;

use App\Integrations\Enums\OAuthProvider;

/**
 * A calendar event summary for suggestion UI (any provider).
 */
final readonly class CalendarEventData
{
    /**
     * @param  list<string>  $attendeeEmails
     */
    public function __construct(
        public string $id,
        public string $summary,
        public ?string $htmlLink,
        public ?string $startsAt,
        public ?string $endsAt,
        public array $attendeeEmails,
        public bool $matchesClientEmail,
        public OAuthProvider $provider = OAuthProvider::Google,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     summary: string,
     *     html_link: string|null,
     *     starts_at: string|null,
     *     ends_at: string|null,
     *     attendee_emails: list<string>,
     *     matches_client_email: bool,
     *     provider: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'summary' => $this->summary,
            'html_link' => $this->htmlLink,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'attendee_emails' => $this->attendeeEmails,
            'matches_client_email' => $this->matchesClientEmail,
            'provider' => $this->provider->value,
        ];
    }
}
