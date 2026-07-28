<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Requests;

use App\Accounts\Http\Concerns\EnsuresAuthenticatedUser;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreWorkspaceRequest extends FormRequest
{
    use EnsuresAuthenticatedUser;
    use LocalizesValidationAttributes;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
        ];
    }
}
