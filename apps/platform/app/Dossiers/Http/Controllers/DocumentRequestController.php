<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers;

use App\Dossiers\Actions\Builder\ApplyQuestionnaireTemplateToDossierAction;
use App\Dossiers\Actions\Builder\CreateDocumentRequestAction;
use App\Dossiers\Actions\Builder\DeleteDocumentRequestAction;
use App\Dossiers\Actions\Builder\ReorderDocumentRequestsAction;
use App\Dossiers\Actions\Builder\UpdateDocumentRequestAction;
use App\Dossiers\Http\Requests\Builder\ApplyQuestionnaireTemplateRequest;
use App\Dossiers\Http\Requests\Builder\ReorderDocumentRequestsRequest;
use App\Dossiers\Http\Requests\Builder\StoreDocumentRequestRequest;
use App\Dossiers\Http\Requests\Builder\UpdateDocumentRequestRequest;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Form-builder mutations for dossier questionnaire items.
 */
final class DocumentRequestController extends Controller
{
    public function store(
        Tenant $tenant,
        StoreDocumentRequestRequest $request,
        Dossier $dossier,
        CreateDocumentRequestAction $createDocumentRequest,
    ): RedirectResponse {
        $createDocumentRequest->handle($dossier, $request->validated());

        return $this->redirectToBuilder($dossier);
    }

    public function update(
        Tenant $tenant,
        UpdateDocumentRequestRequest $request,
        Dossier $dossier,
        DocumentRequest $documentRequest,
        UpdateDocumentRequestAction $updateDocumentRequest,
    ): RedirectResponse {
        $updateDocumentRequest->handle($documentRequest, $request->validated());

        return $this->redirectToBuilder($dossier);
    }

    public function destroy(
        Tenant $tenant,
        Dossier $dossier,
        DocumentRequest $documentRequest,
        DeleteDocumentRequestAction $deleteDocumentRequest,
    ): RedirectResponse {
        $this->authorize('view', $dossier);
        $this->authorize('delete', $documentRequest);

        $deleteDocumentRequest->handle($documentRequest);

        return $this->redirectToBuilder($dossier);
    }

    public function reorder(
        Tenant $tenant,
        ReorderDocumentRequestsRequest $request,
        Dossier $dossier,
        ReorderDocumentRequestsAction $reorderDocumentRequests,
    ): RedirectResponse|Response {
        $reorderDocumentRequests->handle(
            $dossier,
            $request->array('document_request_ids'),
        );

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return $this->redirectToBuilder($dossier);
    }

    public function applyTemplate(
        Tenant $tenant,
        ApplyQuestionnaireTemplateRequest $request,
        Dossier $dossier,
        ApplyQuestionnaireTemplateToDossierAction $applyQuestionnaireTemplateToDossier,
    ): RedirectResponse {
        // Action resolves the QuestionnaireTemplate so this controller stays in Dossiers.
        $applyQuestionnaireTemplateToDossier->handle(
            $dossier,
            (int) $request->validated('questionnaire_template_id'),
        );

        return $this->redirectToBuilder($dossier);
    }

    /** Form builder mutations stay on the full-bleed builder page. */
    private function redirectToBuilder(Dossier $dossier): RedirectResponse
    {
        return to_route(
            'workspaces.dossiers.builder',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }
}
