<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Controllers;

use App\ClientPortal\Actions\SubmitPortalAnswerAction;
use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Http\Requests\StorePortalQuestionnaireAnswerRequest;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Models\DocumentRequest;
use App\Shared\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

use function array_key_exists;

final class ClientPortalAnswerController extends Controller
{
    /**
     * Accept a text or yes/no answer from the client portal.
     */
    public function store(
        StorePortalQuestionnaireAnswerRequest $request,
        DocumentRequest $documentRequest,
        SubmitPortalAnswerAction $submitPortalAnswer,
    ): RedirectResponse {
        $grant = $this->grantFromRequest($request);

        abort_unless($documentRequest->dossier_id === $grant->dossier_id, 404);

        $validated = $request->validated();

        $submitPortalAnswer->handle(
            $documentRequest,
            $grant,
            $validated['answer_text'] ?? null,
            array_key_exists('answer_boolean', $validated)
                ? (bool) $validated['answer_boolean']
                : null,
            isset($validated['answer_option_ids'])
                ? array_values($validated['answer_option_ids'])
                : null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Answer saved. Thank you.'),
        ]);

        return to_route('portal.show');
    }

    private function grantFromRequest(StorePortalQuestionnaireAnswerRequest $request): ClientAccessGrant
    {
        $grant = $request->attributes->get(ResolveClientPortalGrant::REQUEST_ATTRIBUTE);

        abort_unless($grant instanceof ClientAccessGrant, 404);

        return $grant;
    }
}
