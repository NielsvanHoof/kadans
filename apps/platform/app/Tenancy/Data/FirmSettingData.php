<?php

declare(strict_types=1);

namespace App\Tenancy\Data;

use App\Tenancy\Settings\IntegerBound;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One firm setting, with everything the form needs to explain itself.
 *
 * `override` is what the firm chose, or null when it has chosen nothing;
 * `effective` is what the platform actually applies. Shipping both means the
 * UI can leave the field empty and still say which value is in force, without
 * a second source of truth for the platform defaults.
 */
#[TypeScript]
final class FirmSettingData extends Data
{
    public function __construct(
        public readonly ?int $override,
        public readonly ?int $effective,
        public readonly ?int $platformDefault,
        public readonly int $min,
        public readonly ?int $max,
    ) {}

    public static function fromBound(IntegerBound $bound, ?int $override): self
    {
        return new self(
            override: $override,
            effective: $bound->resolve($override),
            platformDefault: $bound->platform(),
            min: $bound->min(),
            max: $bound->max(),
        );
    }
}
