<?php

declare(strict_types=1);

namespace App\Tenancy\Data;

use App\Accounts\Enums\TwoFactorEnforcement;
use App\Tenancy\Models\TenantSetting;
use App\Tenancy\Settings\FirmSettingBounds;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

use function array_map;

#[TypeScript]
final class TenantSecuritySettingsData extends Data
{
    /**
     * @param  list<string>  $allowedEnforcementLevels
     */
    public function __construct(
        public readonly ?string $twoFactorEnforcementOverride,
        public readonly string $twoFactorEnforcement,
        public readonly string $platformTwoFactorEnforcement,
        public readonly array $allowedEnforcementLevels,
    ) {}

    public static function fromModel(?TenantSetting $settings): self
    {
        $bound = FirmSettingBounds::twoFactorEnforcement();
        $override = $settings?->two_factor_enforcement;

        return new self(
            twoFactorEnforcementOverride: $override?->value,
            twoFactorEnforcement: $bound->resolve($override)->value,
            platformTwoFactorEnforcement: $bound->platform()->value,
            allowedEnforcementLevels: array_map(
                static fn (TwoFactorEnforcement $level): string => $level->value,
                $bound->allowed(),
            ),
        );
    }
}
