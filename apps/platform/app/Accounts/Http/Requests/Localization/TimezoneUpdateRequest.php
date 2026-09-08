<?php

declare(strict_types=1);

namespace App\Accounts\Http\Requests;

use App\Accounts\Http\Concerns\AuthorizesAnyAuthenticatedUser;
use App\Accounts\Support\DisplayTimezones;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

use function is_string;

final class TimezoneUpdateRequest extends FormRequest
{
    use AuthorizesAnyAuthenticatedUser;
    use LocalizesValidationAttributes;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Deliberately not Rule::enum: the shortlist in DisplayTimezones is a
            // UI convenience, while any zone a browser reports must be storable.
            'timezone' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * Laravel's `timezone` rule checks PHP's identifier list, which both admits
     * tzdata filenames that `Intl.DateTimeFormat` rejects and omits live aliases
     * it accepts. Validate against ICU instead — the database the browser uses.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $timezone = $this->input('timezone');

            if (is_string($timezone) && DisplayTimezones::isSupported($timezone)) {
                return;
            }

            $validator->errors()->add(
                'timezone',
                __('The selected timezone is not recognised.'),
            );
        });
    }
}
