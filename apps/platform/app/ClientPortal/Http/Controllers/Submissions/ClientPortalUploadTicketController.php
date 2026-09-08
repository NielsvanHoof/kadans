<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Controllers;

use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Http\Requests\StorePortalUploadTicketRequest;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Actions\Uploads\IssueUploadTicketAction;
use App\Dossiers\Enums\UploadPurpose;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Support\DocumentUploadLimits;
use App\Shared\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Mint a presigned PUT for a client-portal upload.
 *
 * The grant, not a user session, is the authorization here, and the tenant
 * sealed into the ticket comes from the granted dossier rather than anything
 * the client sent.
 */
final class ClientPortalUploadTicketController extends Controller
{
    public function store(
        StorePortalUploadTicketRequest $request,
        IssueUploadTicketAction $issueUploadTicket,
    ): JsonResponse {
        $grant = $request->attributes->get(ResolveClientPortalGrant::REQUEST_ATTRIBUTE);

        abort_unless($grant instanceof ClientAccessGrant, 403);

        $dossier = Dossier::query()->findOrFail($grant->dossier_id);

        $declaredBytes = $request->integer('size_bytes');

        /*
         * Check the per-dossier cap here, before the client spends bandwidth on
         * a file that would be rejected on arrival. This is the friendly
         * version of the check; it reads a pre-write total outside any
         * transaction, so concurrent requests can all pass it.
         * SubmitPortalUploadAction re-checks under the dossier lock, and that
         * one is authoritative.
         */
        $usedBytes = DocumentUploadLimits::portalBytesUsedForDossier($dossier->id);

        if ($usedBytes + $declaredBytes > DocumentUploadLimits::MAX_PORTAL_BYTES_PER_DOSSIER) {
            throw ValidationException::withMessages([
                'file' => DocumentUploadLimits::portalCapMessage(),
            ]);
        }

        $ticket = $issueUploadTicket->handle(
            UploadPurpose::Document,
            $dossier->tenant_id,
            $request->string('filename')->toString(),
            $request->string('content_type')->toString(),
            $declaredBytes,
            'file',
        );

        return response()->json($ticket->toArray());
    }
}
