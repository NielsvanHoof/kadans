<?php

declare(strict_types=1);

namespace App\Platform\Http\Requests\DataSubject;

use App\Platform\Enums\DataSubjectRequestType;
use App\Platform\Models\DataSubjectRequest;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDataSubjectRequestRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        return $this->user()?->can('create', DataSubjectRequest::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'type' => ['required', Rule::enum(DataSubjectRequestType::class)],
        ];
    }
}
