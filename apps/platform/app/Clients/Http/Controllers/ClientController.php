<?php

declare(strict_types=1);

namespace App\Clients\Http\Controllers;

use App\Accounts\Models\User;
use App\Clients\Actions\BulkDeleteClientsAction;
use App\Clients\Actions\BulkRestoreClientsAction;
use App\Clients\Actions\CreateClientAction;
use App\Clients\Actions\DeleteClientAction;
use App\Clients\Actions\RestoreClientAction;
use App\Clients\Actions\UpdateClientAction;
use App\Clients\Http\Requests\BulkArchiveClientsRequest;
use App\Clients\Http\Requests\BulkRestoreClientsRequest;
use App\Clients\Http\Requests\StoreClientRequest;
use App\Clients\Http\Requests\UpdateClientRequest;
use App\Clients\Models\Client;
use App\Clients\Queries\FetchArchivedClientsQuery;
use App\Clients\Queries\FetchClientIndexQuery;
use App\Clients\Queries\FetchClientShowQuery;
use App\Dossiers\Queries\TenantHasAnyDossiersQuery;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class ClientController extends Controller
{
    public function index(Tenant $tenant, FetchClientIndexQuery $fetchClientIndex, Request $request): Response
    {
        $this->authorize('viewAny', Client::class);

        return Inertia::render('clients/index', [
            'clients' => $fetchClientIndex->handle(),
            ...$fetchClientIndex->indexQueryProps(),
            'can_manage' => $request->user()?->can('create', Client::class) ?? false,
        ]);
    }

    public function create(Tenant $tenant): Response
    {
        $this->authorize('create', Client::class);

        return Inertia::render('clients/create');
    }

    public function archived(
        Tenant $tenant,
        FetchArchivedClientsQuery $fetchArchivedClients,
        Request $request,
    ): Response {
        $this->authorize('viewAny', Client::class);

        return Inertia::render('clients/archived', [
            'clients' => $fetchArchivedClients->handle(),
            ...$fetchArchivedClients->indexQueryProps(),
            'can_restore' => $request->user()?->can('restoreAny', Client::class) ?? false,
        ]);
    }

    public function store(
        Tenant $tenant,
        StoreClientRequest $request,
        CreateClientAction $createClient,
        TenantHasAnyDossiersQuery $tenantHasAnyDossiers,
    ): RedirectResponse {
        $createClient->handle($request->validated());

        // First client with no dossiers yet → send staff into dossier creation.
        if (! $tenantHasAnyDossiers->handle()) {
            return to_route(
                'workspaces.dossiers.create',
                $this->workspaceRouteParameters(),
            );
        }

        return to_route(
            'workspaces.clients.index',
            $this->workspaceRouteParameters(),
        );
    }

    public function show(
        Tenant $tenant,
        Client $client,
        FetchClientShowQuery $fetchClientShow,
    ): Response {
        $this->authorize('view', $client);

        return Inertia::render('clients/show', $fetchClientShow->handle($client));
    }

    public function edit(Tenant $tenant, Client $client): Response
    {
        $this->authorize('update', $client);

        return Inertia::render('clients/edit', [
            'client' => $client->only(['id', 'name', 'email']),
        ]);
    }

    public function update(
        Tenant $tenant,
        UpdateClientRequest $request,
        Client $client,
        UpdateClientAction $updateClient,
    ): RedirectResponse {
        $updateClient->handle($client, $request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Client details updated.'),
        ]);

        return to_route(
            'workspaces.clients.show',
            $this->workspaceRouteParameters(['client' => $client]),
        );
    }

    public function destroy(
        Tenant $tenant,
        Client $client,
        DeleteClientAction $deleteClient,
    ): RedirectResponse {
        $this->authorize('delete', $client);

        $user = request()->user();

        if (! $user instanceof User) {
            abort(HttpResponse::HTTP_FORBIDDEN);
        }

        $deleteClient->handle($client, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Client archived.'),
        ]);

        return to_route(
            'workspaces.clients.index',
            $this->workspaceRouteParameters(),
        );
    }

    public function restore(
        Tenant $tenant,
        Client $client,
        RestoreClientAction $restoreClient,
    ): RedirectResponse {
        $this->authorize('restore', $client);

        $user = request()->user();

        if (! $user instanceof User) {
            abort(HttpResponse::HTTP_FORBIDDEN);
        }

        $restoreClient->handle($client, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Client restored. Archived dossiers remain in the dossier archive.'),
        ]);

        return to_route(
            'workspaces.clients.show',
            $this->workspaceRouteParameters(['client' => $client]),
        );
    }

    public function bulkArchive(
        Tenant $tenant,
        BulkArchiveClientsRequest $request,
        BulkDeleteClientsAction $bulkDeleteClients,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(HttpResponse::HTTP_FORBIDDEN);
        }

        $clientsQuery = Client::query()->with('tenant');
        $clientsQuery->getQuery()->whereIn('id', $request->clientIds());
        $clients = $clientsQuery->get();

        $count = $bulkDeleteClients->handle($clients, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $count === 1
                ? __('1 client archived.')
                : __(':count clients archived.', ['count' => $count]),
        ]);

        return to_route(
            'workspaces.clients.index',
            $this->workspaceRouteParameters(),
        );
    }

    public function bulkRestore(
        Tenant $tenant,
        BulkRestoreClientsRequest $request,
        BulkRestoreClientsAction $bulkRestoreClients,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(HttpResponse::HTTP_FORBIDDEN);
        }

        $clientsQuery = Client::onlyTrashed()->with('tenant');
        $clientsQuery->getQuery()->whereIn('id', $request->clientIds());
        $clients = $clientsQuery->get();

        $count = $bulkRestoreClients->handle($clients, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $count === 1
                ? __('1 client restored. Archived dossiers remain in the dossier archive.')
                : __(':count clients restored. Archived dossiers remain in the dossier archive.', ['count' => $count]),
        ]);

        return to_route(
            'workspaces.clients.archived',
            $this->workspaceRouteParameters(),
        );
    }
}
