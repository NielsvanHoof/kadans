<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers;

use App\Dossiers\Actions\Builder\ApplyQuestionnaireTemplateToDossierAction;
use App\Dossiers\Actions\Builder\CreateDocumentRequestAction;
use App\Dossiers\Actions\Builder\DeleteDocumentRequestAction;
use App\Dossiers\Actions\Builder\ReorderDocumentRequestsAction;
use App\Dossiers\Actions\Builder\SyncDossierDocumentRequestsAction;
use App\Dossiers\Actions\Builder\UpdateDocumentRequestAction;
use App\Dossiers\Data\StaffDocumentRequestData;
use App\Dossiers\Http\Requests\Builder\ApplyQuestionnaireTemplateRequest;
use App\Dossiers\Http\Requests\Builder\ReorderDocumentRequestsRequest;
use App\Dossiers\Http\Requests\Builder\StoreDocumentRequestRequest;
use App\Dossiers\Http\Requests\Builder\SyncDocumentRequestsRequest;
use App\Dossiers\Http\Requests\Builder\UpdateDocumentRequestRequest;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Form-builder mutations for dossier questionnaire items.
 * Inertia redirects keep classic form posts working; JSON powers the builder island.
 */
final class DocumentRequestController extends Controller
{
    public function store(
        Tenant $tenant,
        StoreDocumentRequestRequest $request,
        Dossier $dossier,
        CreateDocumentRequestAction $createDocumentRequest,
    ): RedirectResponse|JsonResponse {
        $documentRequest = $createDocumentRequest->handle($dossier, $request->validated());

        if ($request->expectsJson()) {
            return response()->json([
                'document_request' => StaffDocumentRequestData::fromModel($documentRequest)->toArray(),
            ], 201);
        }

        return $this->redirectToBuilder($dossier);
    }

    public function update(
        Tenant $tenant,
        UpdateDocumentRequestRequest $request,
        Dossier $dossier,
        DocumentRequest $documentRequest,
        UpdateDocumentRequestAction $updateDocumentRequest,
    ): RedirectResponse|JsonResponse {
        $documentRequest = $updateDocumentRequest->handle($documentRequest, $request->validated());

        if ($request->expectsJson()) {
            return response()->json([
                'document_request' => StaffDocumentRequestData::fromModel($documentRequest)->toArray(),
            ]);
        }

        return $this->redirectToBuilder($dossier);
    }

    public function destroy(
        Tenant $tenant,
        Request $request,
        Dossier $dossier,
        DocumentRequest $documentRequest,
        DeleteDocumentRequestAction $deleteDocumentRequest,
    ): RedirectResponse|Response {
        $this->authorize('view', $dossier);
        $this->authorize('delete', $documentRequest);

        $user = $request->user();
        abort_unless(
            $user !== null && $dossier->isBuilderLockedBy($user),
            403,
        );

        $deleteDocumentRequest->handle($documentRequest);

        if ($request->expectsJson()) {
            return response()->noContent();
        }

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

    /**
     * Persist the full builder draft in one shot (create / update / delete / reorder).
     */
    public function sync(
        Tenant $tenant,
        SyncDocumentRequestsRequest $request,
        Dossier $dossier,
        SyncDossierDocumentRequestsAction $syncDossierDocumentRequests,
    ): JsonResponse {
        /** @var list<array<string, mixed>> $items */
        $items = array_values($request->validated('items'));

        $documentRequests = $syncDossierDocumentRequests->handle($dossier, $items);

        $toast = [
            'type' => 'success',
            'message' => __('Form saved.'),
        ];

        // Same flash shape as redirecting mutations — JSON clients apply it via router.flash().
        Inertia::flash('toast', $toast);

        return response()->json([
            'document_requests' => $documentRequests,
            'flash' => [
                'toast' => $toast,
            ],
        ]);
    }

    public function applyTemplate(
        Tenant $tenant,
        ApplyQuestionnaireTemplateRequest $request,
        Dossier $dossier,
        ApplyQuestionnaireTemplateToDossierAction $applyQuestionnaireTemplateToDossier,
    ): RedirectResponse {
        // Action resolves the QuestionnaireTemplate so this controller stays in Dossiers.
        // Bulk replace still uses an Inertia reload — full item list changes.
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
