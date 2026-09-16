<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Controllers\Submissions;

use App\ClientPortal\Actions\Submissions\SubmitPortalAnswerAction;
use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Http\Requests\Submissions\StorePortalQuestionnaireAnswerRequest;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Queries\Access\ResolveGrantedDossierQuery;
use App\Dossiers\Models\DocumentRequest;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use Illuminate\Http\RedirectResponse;

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
        ResolveGrantedDossierQuery $resolveGrantedDossier,
    ): RedirectResponse {
        $grant = $this->grantFromRequest($request);

        $dossier = $resolveGrantedDossier->handle($grant, $documentRequest->dossier_id);

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

        Toast::success(__('Answer saved. Thank you.'));

        return to_route('portal.dossiers.show', ['dossier' => $dossier->getKey()]);
    }

    private function grantFromRequest(StorePortalQuestionnaireAnswerRequest $request): ClientAccessGrant
    {
        $grant = $request->attributes->get(ResolveClientPortalGrant::REQUEST_ATTRIBUTE);

        abort_unless($grant instanceof ClientAccessGrant, 404);

        return $grant;
    }
}
