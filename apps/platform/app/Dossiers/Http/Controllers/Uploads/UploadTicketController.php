<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers\Uploads;

use App\Dossiers\Actions\Uploads\IssueUploadTicketAction;
use App\Dossiers\Http\Requests\Uploads\StoreUploadTicketRequest;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Mint a presigned PUT for a staff upload.
 *
 * Scoped to the current workspace: the tenant is taken from the route binding
 * and sealed into the ticket, so the object key belongs to this workspace and
 * the ticket can only ever be redeemed against it.
 */
final class UploadTicketController extends Controller
{
    public function store(
        Tenant $tenant,
        StoreUploadTicketRequest $request,
        IssueUploadTicketAction $issueUploadTicket,
    ): JsonResponse {
        $ticket = $issueUploadTicket->handle(
            $request->purpose(),
            $tenant->id,
            $request->string('filename')->toString(),
            $request->string('content_type')->toString(),
            $request->integer('size_bytes'),
            'file',
        );

        return response()->json($ticket->toArray());
    }
}
