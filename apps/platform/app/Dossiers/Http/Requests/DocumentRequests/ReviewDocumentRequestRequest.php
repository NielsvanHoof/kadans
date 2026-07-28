<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\DocumentRequests;

use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewDocumentRequestRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        $dossier = $this->route('dossier');
        $documentRequest = $this->route('documentRequest');
        $user = $this->user();

        return $user !== null
            && $dossier instanceof Dossier
            && $documentRequest instanceof DocumentRequest
            && $user->can('review', $documentRequest);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                Rule::in([
                    DocumentRequestStatus::Accepted->value,
                    DocumentRequestStatus::Rejected->value,
                ]),
            ],
            'rejection_reason' => [
                Rule::requiredIf($this->input('decision') === DocumentRequestStatus::Rejected->value),
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }
}
