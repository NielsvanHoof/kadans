<?php

declare(strict_types=1);

namespace App\Tenancy\Settings;

use Closure;

use function max;
use function min;

/**
 * One numeric firm setting: what a firm may choose, and what the value means
 * when it chooses nothing.
 *
 * The point of this class is that the write-time validation rules and the
 * read-time clamp are derived from the *same* pair of numbers. Stating a bound
 * in a Form Request and again at the point of use is how the two drift, and a
 * drift here means either a value a firm cannot save but the code accepts, or
 * one it can save that the code then quietly ignores.
 *
 * The platform value is a closure because some of it is deploy-time config that
 * must not be captured at container-build time.
 */
final readonly class IntegerBound
{
    /**
     * @param  Closure(): ?int  $platform  The inherited value; null when the setting has no platform default.
     * @param  Closure(): ?int  $hardMax  Null for no ceiling beyond the platform comparison.
     */
    public function __construct(
        private Closure $platform,
        private int $hardMin,
        private Closure $hardMax,
        private ClampDirection $direction,
    ) {}

    public function platform(): ?int
    {
        return ($this->platform)();
    }

    /**
     * The effective value for a firm: its override when it set one, otherwise
     * the platform value. An override outside the allowed band is clamped
     * rather than honoured, so a row edited directly in the database cannot
     * weaken a setting past what the firm could have saved through the form.
     */
    public function resolve(?int $override): ?int
    {
        if ($override === null) {
            return $this->platform();
        }

        $ceiling = $this->max();

        return max(
            $this->min(),
            $ceiling === null ? $override : min($ceiling, $override),
        );
    }

    public function min(): int
    {
        $platform = $this->platform();

        if ($this->direction === ClampDirection::HigherIsStricter && $platform !== null) {
            return max($this->hardMin, $platform);
        }

        return $this->hardMin;
    }

    public function max(): ?int
    {
        $hardMax = ($this->hardMax)();
        $platform = $this->platform();

        if ($this->direction === ClampDirection::LowerIsStricter && $platform !== null) {
            return $hardMax === null ? $platform : min($hardMax, $platform);
        }

        return $hardMax;
    }

    /**
     * @return list<string>
     */
    public function rules(): array
    {
        $rules = ['nullable', 'integer', 'min:'.$this->min()];
        $max = $this->max();

        if ($max !== null) {
            $rules[] = 'max:'.$max;
        }

        return $rules;
    }
}
