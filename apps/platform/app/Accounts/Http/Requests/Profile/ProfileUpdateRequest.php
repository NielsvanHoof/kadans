<?php

declare(strict_types=1);

namespace App\Accounts\Http\Requests\Profile;

use App\Accounts\Concerns\ProfileValidationRules;
use App\Accounts\Http\Concerns\AuthorizesAnyAuthenticatedUser;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ProfileUpdateRequest extends FormRequest
{
    use AuthorizesAnyAuthenticatedUser, ProfileValidationRules;
    use LocalizesValidationAttributes;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->profileRules($this->authenticatedUser()->id);
    }
}
