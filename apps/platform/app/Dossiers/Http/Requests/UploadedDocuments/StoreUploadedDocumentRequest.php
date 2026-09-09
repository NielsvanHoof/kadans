<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\UploadedDocuments;

use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Support\Uploads\DocumentUploadLimits;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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

        /*
         * The files are already in S3 by the time this runs, so there is
         * nothing here to validate as a file. What arrives is one opaque
         * upload ticket per document; type and size are checked by
         * ClaimUploadTicketAction against the object S3 actually stored,
         * which is the only place either is knowable.
         */
        return [
            'documents' => [
                'required',
                'array',
                'min:1',
                'max:'.($allowMultiple ? DocumentUploadLimits::MAX_FILES_WHEN_MULTIPLE : 1),
            ],
            'documents.*' => ['required', 'string'],
        ];
    }
}
