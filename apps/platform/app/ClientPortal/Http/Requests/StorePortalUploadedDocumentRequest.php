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
            && $documentRequest->allowsMultipleUploads();

        // One opaque upload ticket per document. See the staff equivalent:
        // type and size belong to ClaimUploadTicketAction, which can read the
        // stored object, and the per-dossier byte cap is pre-checked when the
        // ticket is issued and enforced under the dossier lock on write.
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
