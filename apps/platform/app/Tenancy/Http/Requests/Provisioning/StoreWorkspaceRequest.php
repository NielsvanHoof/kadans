<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Requests\Provisioning;

use App\Accounts\Http\Concerns\AuthorizesAnyAuthenticatedUser;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreWorkspaceRequest extends FormRequest
{
    use AuthorizesAnyAuthenticatedUser;
    use LocalizesValidationAttributes;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
        ];
    }
}
