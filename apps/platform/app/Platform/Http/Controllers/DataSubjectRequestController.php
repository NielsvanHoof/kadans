<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Clients\Models\Client;
use App\Platform\Actions\DataSubject\LogDataSubjectRequestAction;
use App\Platform\Actions\DataSubject\RefuseDataSubjectRequestAction;
use App\Platform\Enums\DataSubjectRequestType;
use App\Platform\Http\Requests\RefuseDataSubjectRequestRequest;
use App\Platform\Http\Requests\StoreDataSubjectRequestRequest;
use App\Platform\Jobs\AnonymizeDataSubjectJob;
use App\Platform\Jobs\GenerateDataSubjectExportJob;
use App\Platform\Models\DataSubjectRequest;
use App\Platform\Queries\FetchDataSubjectRequestIndexQuery;
use App\Shared\Data\PersonOptionData;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function sprintf;

final class DataSubjectRequestController extends Controller
{
    use ResolvesCurrentUser;

    /**
     * Ceiling on the client picker. Sized well above any realistic firm's book
     * so the picker stays a plain select, while still bounding the query.
     */
    private const int CLIENT_PICKER_LIMIT = 500;

    public function index(Tenant $tenant, FetchDataSubjectRequestIndexQuery $fetchIndex): Response
    {
        $this->authorize('viewAny', DataSubjectRequest::class);

        $canManage = $this->currentUser()->can('create', DataSubjectRequest::class);

        return Inertia::render('data-subject-requests/index', [
            'requests' => $fetchIndex->handle(),
            ...$fetchIndex->indexQueryProps(),
            'can_manage' => $canManage,
            // Only needed to populate the "log a request" picker.
            'clients' => $canManage ? $this->clientOptions() : [],
        ]);
    }

    public function store(
        Tenant $tenant,
        StoreDataSubjectRequestRequest $request,
        LogDataSubjectRequestAction $logDataSubjectRequest,
    ): RedirectResponse {
        $client = Client::query()->whereKey($request->validated('client_id'))->firstOrFail();

        $logDataSubjectRequest->handle(
            $client,
            $this->currentUser(),
            DataSubjectRequestType::from((string) $request->validated('type')),
        );

        Toast::success(__('Request logged. It is due within :days days.', [
            'days' => Config::integer('retention.data_subject_response_days'),
        ]));

        return to_route('workspaces.data-subject-requests.index', $this->workspaceRouteParameters());
    }

    /**
     * Export and erasure both run off the request cycle: export archives every
     * stored file; erasure can touch many dossiers, storage objects, and rows.
     */
    public function fulfil(
        Tenant $tenant,
        DataSubjectRequest $dataSubjectRequest,
    ): RedirectResponse {
        $this->authorize('fulfil', $dataSubjectRequest);

        if ($dataSubjectRequest->type === DataSubjectRequestType::Export) {
            dispatch(new GenerateDataSubjectExportJob($dataSubjectRequest->id))->afterCommit();

            Toast::success(__('Preparing the export. You\'ll get a download link when it\'s ready.'));
        } else {
            dispatch(new AnonymizeDataSubjectJob(
                $dataSubjectRequest->id,
                $this->currentUser()->id,
            ))->afterCommit();

            Toast::success(__('Erasure is running. The client’s identity will be removed while retained records are kept.'));
        }

        return to_route('workspaces.data-subject-requests.index', $this->workspaceRouteParameters());
    }

    public function refuse(
        Tenant $tenant,
        RefuseDataSubjectRequestRequest $request,
        DataSubjectRequest $dataSubjectRequest,
        RefuseDataSubjectRequestAction $refuseDataSubjectRequest,
    ): RedirectResponse {
        $this->authorize('refuse', $dataSubjectRequest);

        $refuseDataSubjectRequest->handle(
            $dataSubjectRequest,
            $this->currentUser(),
            (string) $request->validated('refusal_reason'),
        );

        Toast::success(__('Request refused. The reason has been recorded.'));

        return to_route('workspaces.data-subject-requests.index', $this->workspaceRouteParameters());
    }

    /**
     * Streams rather than redirecting to a presigned URL: this archive is every
     * document the firm holds about one person, so it must not become a link
     * that survives the session it was issued in.
     */
    public function download(
        Tenant $tenant,
        DataSubjectRequest $dataSubjectRequest,
        LogAuditActivityAction $logAuditActivity,
    ): StreamedResponse {
        $this->authorize('download', $dataSubjectRequest);

        $logAuditActivity->handle(
            AuditEvent::DataSubjectExportDownloaded,
            $dataSubjectRequest,
            ['client_id' => $dataSubjectRequest->client_id],
        );

        return Storage::disk((string) $dataSubjectRequest->disk)->download(
            (string) $dataSubjectRequest->path,
            sprintf('data-export-%d.zip', $dataSubjectRequest->id),
        );
    }

    /**
     * Options for the "log a request" picker.
     *
     * Only the three columns the picker renders are selected, and the list is
     * bounded: this used to load every full client row the tenant had. A firm
     * past the cap picks the client from the client list instead, which is
     * paginated and searchable.
     *
     * @return list<PersonOptionData>
     */
    private function clientOptions(): array
    {
        $options = [];

        // orderBy/limit go through getQuery(): phpstan-strict-rules reads a
        // chained call on the Eloquent builder as a dynamic static call.
        $clientQuery = Client::select(['id', 'name', 'email']);
        $clientQuery->getQuery()
            ->orderBy('name')
            ->limit(self::CLIENT_PICKER_LIMIT);

        $clients = $clientQuery->get();

        foreach ($clients as $client) {
            $options[] = new PersonOptionData($client->id, $client->name, $client->email);
        }

        return $options;
    }
}
