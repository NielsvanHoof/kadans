<?php

declare(strict_types=1);

namespace App\Tenancy\Settings;

use App\Accounts\Enums\TwoFactorEnforcement;
use Illuminate\Support\Facades\Config;

use function array_filter;
use function array_map;
use function array_values;

/**
 * Which enforcement levels a firm may choose, given the platform floor.
 *
 * The read-time clamp here is deliberately duplicated by
 * RequireTwoFactorAuthentication, which applies `strictest()` itself rather
 * than trusting whatever reaches it. That is the one duplication worth keeping
 * in this feature: it is the only setting where a wrong answer is a security
 * incident rather than an inconvenience.
 */
final readonly class TwoFactorEnforcementBound
{
    public function platform(): TwoFactorEnforcement
    {
        return TwoFactorEnforcement::fromConfigValue(
            Config::get('security.two_factor.enforcement'),
        );
    }

    public function resolve(?TwoFactorEnforcement $override): TwoFactorEnforcement
    {
        $platform = $this->platform();

        return TwoFactorEnforcement::strictest($platform, $override ?? $platform);
    }

    /**
     * @return list<TwoFactorEnforcement>
     */
    public function allowed(): array
    {
        $platform = $this->platform();

        return array_values(array_filter(
            TwoFactorEnforcement::cases(),
            static fn (TwoFactorEnforcement $level): bool => $level->isAtLeastAsStrictAs($platform),
        ));
    }

    /**
     * @return list<mixed>
     */
    public function rules(): array
    {
        return [
            'nullable',
            'string',
            'in:'.implode(',', array_map(
                static fn (TwoFactorEnforcement $level): string => $level->value,
                $this->allowed(),
            )),
        ];
    }
}
