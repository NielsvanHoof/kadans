<?php

declare(strict_types=1);

use App\Accounts\Enums\TwoFactorEnforcement;
use App\Tenancy\Settings\FirmSettingBounds;
use App\Tenancy\Settings\IntegerBound;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;

covers(FirmSettingBounds::class);

/**
 * @return list<int>
 */
function probeValuesForBoundsTest(IntegerBound $bound): array
{
    $min = $bound->min();
    $max = $bound->max() ?? $min + 10;

    return array_values(array_unique([
        max(0, $min - 2), $min - 1, $min, $min + 1,
        intdiv($min + $max, 2),
        $max - 1, $max, $max + 1, $max + 10,
    ]));
}

test('validation and the read-time clamp agree on every value', function (string $name): void {
    $bound = FirmSettingBounds::$name();

    foreach (probeValuesForBoundsTest($bound) as $value) {
        $passes = Validator::make(
            ['value' => $value],
            ['value' => $bound->rules()],
        )->passes();

        expect($bound->resolve($value) === $value)->toBe(
            $passes,
            "$name disagreed on $value: validation ".($passes ? 'accepted' : 'rejected')
                .' it but the clamp returned '.var_export($bound->resolve($value), true),
        );
    }
})->with([
    'portal idle minutes' => 'portalSessionIdleMinutes',
    'portal absolute minutes' => 'portalSessionAbsoluteMinutes',
    'reminder interval days' => 'reminderIntervalDays',
    'retention archived days' => 'retentionArchivedDays',
    'retention activity log days' => 'retentionActivityLogDays',
]);

test('an unset setting inherits the platform value', function (): void {
    Config::set('portal.session_idle_minutes', 12);
    Config::set('retention.archived_days', 1095);

    expect(FirmSettingBounds::portalSessionIdleMinutes()->resolve(null))->toBe(12)
        ->and(FirmSettingBounds::retentionArchivedDays()->resolve(null))->toBe(1095)
        ->and(FirmSettingBounds::reminderIntervalDays()->resolve(null))->toBeNull();
});

test('a firm may shorten a portal window but never lengthen it', function (): void {
    Config::set('portal.session_idle_minutes', 15);
    $bound = FirmSettingBounds::portalSessionIdleMinutes();

    expect($bound->resolve(5))->toBe(5)
        ->and($bound->resolve(30))->toBe(15)
        ->and(Validator::make(['value' => 30], ['value' => $bound->rules()])->passes())->toBeFalse();
});

test('the portal idle ceiling stays below the session lifetime', function (): void {
    Config::set('session.lifetime', 20);
    Config::set('portal.session_idle_minutes', 120);

    expect(FirmSettingBounds::portalSessionIdleMinutes()->max())->toBe(19);
});

test('a firm may move retention either way inside the band', function (): void {
    Config::set('retention.archived_days', 1095);
    Config::set('retention.tenant_bounds.archived_days', ['min' => 365, 'max' => 3650]);
    $bound = FirmSettingBounds::retentionArchivedDays();

    expect($bound->resolve(365))->toBe(365)
        ->and($bound->resolve(3650))->toBe(3650)
        ->and($bound->resolve(100))->toBe(365)
        ->and($bound->resolve(9999))->toBe(3650);
});

test('a firm may only tighten two-factor enforcement', function (): void {
    Config::set('security.two_factor.enforcement', TwoFactorEnforcement::ByPermission->value);
    $bound = FirmSettingBounds::twoFactorEnforcement();

    expect($bound->resolve(TwoFactorEnforcement::AllStaff))->toBe(TwoFactorEnforcement::AllStaff)
        ->and($bound->resolve(TwoFactorEnforcement::Off))->toBe(TwoFactorEnforcement::ByPermission)
        ->and($bound->resolve(null))->toBe(TwoFactorEnforcement::ByPermission)
        ->and($bound->allowed())->not->toContain(TwoFactorEnforcement::Off);
});

test('an unrecognised configured enforcement fails closed', function (): void {
    Config::set('security.two_factor.enforcement', 'nonsense');

    expect(FirmSettingBounds::twoFactorEnforcement()->platform())
        ->toBe(TwoFactorEnforcement::AllStaff);
});
