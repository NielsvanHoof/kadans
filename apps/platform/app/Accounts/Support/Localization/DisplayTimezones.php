<?php

declare(strict_types=1);

namespace App\Accounts\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Config;
use IntlTimeZone;

use function in_array;
use function sprintf;

/**
 * Timezone identifiers that are safe to hand to a formatter, plus the shortlist
 * offered in settings.
 *
 * Validation goes through ICU rather than PHP's own zone list, because the value
 * ends up in `Intl.DateTimeFormat` in the browser, and the two disagree in both
 * directions:
 *
 * - `new DateTimeZone('CEST')` succeeds, but `Intl.DateTimeFormat` throws a
 *   `RangeError` on it. Thrown during render, that takes the page down — so the
 *   constructor can never be used as the gate.
 * - `timezone_identifiers_list(DateTimeZone::ALL_WITH_BC)` admits four tzdata
 *   *filenames* (`Factory`, `leapseconds`, `localtime`, `tzdata.zi`) that ICU
 *   also rejects. Laravel's `timezone:all_with_bc` rule inherits that hole.
 * - ICU accepts `Asia/Calcutta` and `US/Pacific`, which real browsers report but
 *   PHP's lists reject — those users would otherwise be silently downgraded.
 *
 * PHP's intl extension reads the same ICU database the browser does, which is
 * the property that makes it the right authority here.
 */
final readonly class DisplayTimezones
{
    /**
     * Present in ICU but not a usable zone: a tzdata artefact that some ICU
     * builds (including Node's) reject.
     */
    private const string NotAZone = 'Factory';

    /**
     * Zones offered in settings. Deliberately short — a 600-entry dropdown is
     * unusable, while validation stays permissive so any zone a browser reports
     * still round-trips.
     *
     * @var list<string>
     */
    private const array Shortlist = [
        'UTC',
        'Europe/Lisbon',
        'Europe/London',
        'Europe/Amsterdam',
        'Europe/Brussels',
        'Europe/Berlin',
        'Europe/Paris',
        'Europe/Madrid',
        'Europe/Warsaw',
        'Asia/Dubai',
        'Asia/Singapore',
        'America/New_York',
        'America/Chicago',
        'America/Los_Angeles',
    ];

    /**
     * Whether the identifier can safely be passed to a date formatter.
     */
    public static function isSupported(string $timezone): bool
    {
        if ($timezone === '' || $timezone === self::NotAZone) {
            return false;
        }

        $isSystemTimezone = false;

        // getCanonicalID() returns false for anything ICU does not recognise, and
        // reports non-system IDs (raw offsets such as "+02:00") through the
        // by-reference flag rather than the return value.
        $canonicalId = IntlTimeZone::getCanonicalID($timezone, $isSystemTimezone);

        return $canonicalId !== false && $isSystemTimezone;
    }

    /**
     * The identifier if usable, otherwise null — so callers can fall through to
     * their next source instead of branching on validity themselves.
     */
    public static function sanitize(?string $timezone): ?string
    {
        if ($timezone === null) {
            return null;
        }

        return self::isSupported($timezone) ? $timezone : null;
    }

    /**
     * The zone to present in when nothing better is known.
     *
     * Validated, so a typo'd APP_DISPLAY_TIMEZONE degrades to UTC rather than
     * throwing on every page render and every queued mail.
     */
    public static function default(): string
    {
        return self::sanitize(Config::string('app.display_timezone')) ?? 'UTC';
    }

    /**
     * Selectable zones, ordered west to east, with the resolved zone appended when
     * it sits outside the shortlist so a traveller still sees their own selected.
     *
     * Labels carry the current UTC offset ("Amsterdam (GMT+02:00)") because a city
     * name alone does not tell the reader what it will do to their timestamps. City
     * names are proper nouns and are deliberately not translated.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(?string $currentTimezone = null): array
    {
        $timezones = self::Shortlist;

        $current = self::sanitize($currentTimezone);

        if ($current !== null && ! in_array($current, $timezones, true)) {
            $timezones[] = $current;
        }

        usort($timezones, static function (string $first, string $second): int {
            $byOffset = self::offsetSeconds($first) <=> self::offsetSeconds($second);

            // Same offset (Amsterdam / Brussels / Berlin) — order by name so the
            // list is stable rather than dependent on sort internals.
            return $byOffset === 0 ? strcmp($first, $second) : $byOffset;
        });

        return array_map(
            static fn (string $timezone): array => [
                'value' => $timezone,
                'label' => self::label($timezone),
            ],
            $timezones,
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return self::Shortlist;
    }

    /**
     * "Europe/Amsterdam" reads as "Amsterdam (GMT+02:00)".
     */
    private static function label(string $timezone): string
    {
        $offset = new DateTimeImmutable('now', new DateTimeZone($timezone))
            ->format('P');

        if ($timezone === 'UTC') {
            return sprintf('UTC (GMT%s)', $offset);
        }

        $city = str_contains($timezone, '/')
            ? mb_substr($timezone, (int) mb_strrpos($timezone, '/') + 1)
            : $timezone;

        return sprintf('%s (GMT%s)', str_replace('_', ' ', $city), $offset);
    }

    /**
     * Current offset from UTC, used only to order the list.
     */
    private static function offsetSeconds(string $timezone): int
    {
        return new DateTimeZone($timezone)->getOffset(CarbonImmutable::now());
    }
}
