<?php

declare(strict_types=1);

namespace App\Accounts\Http\Requests;

use App\Accounts\Concerns\PasswordValidationRules;
use App\Accounts\Http\Concerns\EnsuresAuthenticatedUser;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ProfileDeleteRequest extends FormRequest
{
    use EnsuresAuthenticatedUser, PasswordValidationRules;
    use LocalizesValidationAttributes;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }
}
