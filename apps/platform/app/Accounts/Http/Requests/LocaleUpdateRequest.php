<?php

declare(strict_types=1);

namespace App\Accounts\Http\Requests;

use App\Accounts\Enums\Locale;
use App\Accounts\Http\Concerns\AuthorizesAnyAuthenticatedUser;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LocaleUpdateRequest extends FormRequest
{
    use AuthorizesAnyAuthenticatedUser;
    use LocalizesValidationAttributes;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::enum(Locale::class)],
        ];
    }
}
