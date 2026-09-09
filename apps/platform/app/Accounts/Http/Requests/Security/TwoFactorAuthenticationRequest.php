<?php

declare(strict_types=1);

namespace App\Accounts\Http\Requests\Security;

use App\Accounts\Http\Concerns\AuthorizesAnyAuthenticatedUser;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Laravel\Fortify\InteractsWithTwoFactorState;

final class TwoFactorAuthenticationRequest extends FormRequest
{
    use AuthorizesAnyAuthenticatedUser, InteractsWithTwoFactorState;
    use LocalizesValidationAttributes;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }
}
