<?php

declare(strict_types=1);

namespace App\Tenancy\Support\Settings;

use Illuminate\Support\Facades\Config;

use function min;

/**
 * Every per-firm setting's allowed range, declared once.
 *
 * Both halves of a setting come from here: the Form Request asks for `rules()`,
 * and the read path asks for `resolve()`. Anything that states a bound a second
 * time — a `max:30` typed into a Form Request, a `min()` at the point of use —
 * is a drift waiting to happen, and the reminder interval already showed how:
 * its 1..30 range was copied into both StoreDossierRequest and
 * UpdateDossierRequest, so changing it meant changing it twice.
 *
 * Lives in Tenancy rather than beside each consumer because ClientPortal and
 * Dossiers may import Tenancy, while Tenancy may import neither.
 */
final class FirmSettingBounds
{
    public static function twoFactorEnforcement(): TwoFactorEnforcementBound
    {
        return new TwoFactorEnforcementBound;
    }

    /**
     * A firm may only shorten the idle window.
     *
     * The ceiling tracks `session.lifetime` as well as the platform value: the
     * underlying Laravel session idles out too, and a portal window at or above
     * it would promise a client time the session store drops first.
     * ProductionConfigurationValidator refuses to boot production on that, and
     * because a firm can only shorten, its check keeps covering every firm.
     * Deriving the ceiling here too means that stays true even if the
     * stricter-only rule is ever relaxed.
     */
    public static function portalSessionIdleMinutes(): IntegerBound
    {
        return new IntegerBound(
            platform: static fn (): int => Config::integer('portal.session_idle_minutes', 15),
            hardMin: 1,
            hardMax: static fn (): int => min(60, Config::integer('session.lifetime') - 1),
            direction: ClampDirection::LowerIsStricter,
        );
    }

    public static function portalSessionAbsoluteMinutes(): IntegerBound
    {
        return new IntegerBound(
            platform: static fn (): int => Config::integer('portal.session_absolute_minutes', 240),
            hardMin: 1,
            hardMax: static fn (): int => 1440,
            direction: ClampDirection::LowerIsStricter,
        );
    }

    /**
     * The one setting with no platform default: null means the firm has not set
     * a house rhythm, not that it inherits one.
     */
    public static function reminderIntervalDays(): IntegerBound
    {
        return new IntegerBound(
            platform: static fn (): ?int => null,
            hardMin: 1,
            hardMax: static fn (): int => 30,
            direction: ClampDirection::Banded,
        );
    }

    public static function retentionArchivedDays(): IntegerBound
    {
        return self::bandedRetention('archived_days');
    }

    public static function retentionActivityLogDays(): IntegerBound
    {
        return self::bandedRetention('activity_log_days');
    }

    private static function bandedRetention(string $key): IntegerBound
    {
        return new IntegerBound(
            platform: static fn (): int => Config::integer("retention.$key"),
            hardMin: Config::integer("retention.tenant_bounds.$key.min"),
            hardMax: static fn (): int => Config::integer("retention.tenant_bounds.$key.max"),
            direction: ClampDirection::Banded,
        );
    }
}
