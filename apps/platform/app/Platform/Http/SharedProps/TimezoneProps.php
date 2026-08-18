<?php

declare(strict_types=1);

namespace App\Platform\Http\SharedProps;

/**
 * The timezone absolute timestamps are presented in.
 *
 * Only the resolved identifier is shared. The selectable list is a page prop on
 * the settings screen instead, so every response in the app does not carry it.
 *
 * The client formats from this value rather than reading its own
 * `Intl.DateTimeFormat().resolvedOptions().timeZone`, so a server render and the
 * browser produce identical text.
 */
final readonly class TimezoneProps
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $timezone): array
    {
        return [
            'timezone' => $timezone,
        ];
    }
}
