<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Requests;

use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Support\DocumentUploadLimits;
use App\Dossiers\Transitions\DocumentRequestTransitions;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

final class StorePortalUploadedDocumentRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function __construct(
        private readonly DocumentRequestTransitions $documentRequestTransitions,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        $grant = $this->attributes->get(ResolveClientPortalGrant::REQUEST_ATTRIBUTE);
        $documentRequestId = $this->route('documentRequest');

        if (! $grant instanceof ClientAccessGrant || ! is_numeric($documentRequestId)) {
            return false;
        }

        $documentRequest = DocumentRequest::query()->find((int) $documentRequestId);

        // Let the controller return its existing concealed 404 for missing or cross-dossier IDs.
        if (! $documentRequest instanceof DocumentRequest
            || $documentRequest->dossier_id !== $grant->dossier_id) {
            return true;
        }

        $dossier = $documentRequest->dossier;

        return $dossier instanceof Dossier
            && $documentRequest->type === QuestionnaireItemType::File
            && $this->documentRequestTransitions->canSubmit(
                $documentRequest,
                SubmissionContext::Portal,
                $dossier,
            );
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $documentRequestId = $this->route('documentRequest');
        $documentRequest = is_numeric($documentRequestId)
            ? DocumentRequest::query()->find((int) $documentRequestId)
            : null;
        $allowMultiple = $documentRequest instanceof DocumentRequest
            && ($documentRequest->configuration['allow_multiple'] ?? false) === true;

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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $grant = $this->attributes->get(ResolveClientPortalGrant::REQUEST_ATTRIBUTE);

            if (! $grant instanceof ClientAccessGrant) {
                return;
            }

            // Cheap pre-check for a friendly error. The sum is taken before the
            // write and outside any transaction, so it cannot be authoritative:
            // SubmitPortalUploadAction re-checks it under the dossier lock.
            $usedBytes = DocumentUploadLimits::portalBytesUsedForDossier($grant->dossier_id);

            $incomingBytes = collect($this->file('documents', []))
                ->filter(static fn (mixed $file): bool => $file instanceof UploadedFile)
                ->sum(static fn (UploadedFile $file): int => $file->getSize());

            $totalBytes = $usedBytes + (int) $incomingBytes;

            if ($totalBytes <= DocumentUploadLimits::MAX_PORTAL_BYTES_PER_DOSSIER) {
                return;
            }

            $validator->errors()->add('documents', DocumentUploadLimits::portalCapMessage());
        });
    }
}
