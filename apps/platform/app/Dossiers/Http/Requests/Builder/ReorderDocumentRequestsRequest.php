<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\Builder;

use App\Dossiers\Http\Requests\Builder\Concerns\AuthorizesDossierBuilderMutation;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ReorderDocumentRequestsRequest extends FormRequest
{
    use AuthorizesDossierBuilderMutation;
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        return $this->userHoldsBuilderLockForMutation();
    }

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'document_request_ids' => ['required', 'array', 'min:1'],
            'document_request_ids.*' => ['integer', 'distinct'],
        ];
    }
}
