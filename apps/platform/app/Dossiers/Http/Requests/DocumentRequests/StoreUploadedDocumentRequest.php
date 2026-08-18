<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\DocumentRequests;

use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Support\DocumentUploadLimits;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

final class StoreUploadedDocumentRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    /**
     * Staff who can create dossiers may upload files onto document requests.
     */
    public function authorize(): bool
    {
        $documentRequest = $this->route('documentRequest');

        if (! $documentRequest instanceof DocumentRequest) {
            return false;
        }

        return $this->user()?->can('upload', $documentRequest) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $documentRequest = $this->route('documentRequest');
        $allowMultiple = $documentRequest instanceof DocumentRequest
            && $documentRequest->allowsMultipleUploads();

        return [
            'documents' => [
                'required',
                'array',
                'min:1',
                'max:'.($allowMultiple ? DocumentUploadLimits::MAX_FILES_WHEN_MULTIPLE : 1),
            ],
            'documents.*' => [
                File::types(['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx'])
                    ->max(DocumentUploadLimits::MAX_FILE_SIZE),
            ],
        ];
    }
}
