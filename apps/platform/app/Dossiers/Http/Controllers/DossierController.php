<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Clients\Models\Client;
use App\Dossiers\Actions\BulkDeleteDossiersAction;
use App\Dossiers\Actions\BulkRestoreDossiersAction;
use App\Dossiers\Actions\CreateDossierAction;
use App\Dossiers\Actions\DeleteDossierAction;
use App\Dossiers\Actions\RestoreDossierAction;
use App\Dossiers\Actions\UpdateDossierAction;
use App\Dossiers\Http\Requests\BulkArchiveDossiersRequest;
use App\Dossiers\Http\Requests\BulkRestoreDossiersRequest;
use App\Dossiers\Http\Requests\StoreDossierRequest;
use App\Dossiers\Http\Requests\UpdateDossierRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Queries\FetchArchivedDossiersQuery;
use App\Dossiers\Queries\FetchDossierBuilderQuery;
use App\Dossiers\Queries\FetchDossierCreateQuery;
use App\Dossiers\Queries\FetchDossierEditQuery;
use App\Dossiers\Queries\FetchDossierIndexQuery;
use App\Dossiers\Queries\FetchDossierReviewQuery;
use App\Dossiers\Queries\FetchDossierShowQuery;
use App\Shared\Data\PersonOptionData;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\FetchResponsibleStaffOptionsQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\Support\Header;

final class DossierController extends Controller
{
    use ResolvesCurrentUser;

    public function index(Tenant $tenant, FetchDossierIndexQuery $fetchDossierIndex, Request $request): Response
    {
        $this->authorize('viewAny', Dossier::class);

        return Inertia::render('dossiers/index', [
            'dossiers' => $fetchDossierIndex->handle(),
            ...$fetchDossierIndex->indexQueryProps(),
            'can_manage' => $request->user()?->can('create', Dossier::class) ?? false,
        ]);
    }

    public function create(
        Tenant $tenant,
        FetchDossierCreateQuery $getDossierCreateData,
        FetchResponsibleStaffOptionsQuery $fetchResponsibleStaffOptions,
    ): Response {
        $this->authorize('create', Dossier::class);

        return Inertia::render('dossiers/create', [
            'clients' => array_map(
                static fn (PersonOptionData $client): array => $client->toArray(),
                $getDossierCreateData->handle(),
            ),
            'responsible_staff' => array_map(
                static fn (PersonOptionData $staff): array => $staff->toArray(),
                $fetchResponsibleStaffOptions->handle($tenant),
            ),
        ]);
    }

    public function archived(
        Tenant $tenant,
        FetchArchivedDossiersQuery $fetchArchivedDossiers,
        Request $request,
    ): Response {
        $this->authorize('viewAny', Dossier::class);

        return Inertia::render('dossiers/archived', [
            'dossiers' => $fetchArchivedDossiers->handle(),
            ...$fetchArchivedDossiers->indexQueryProps(),
            'can_restore' => $request->user()?->can('restoreAny', Dossier::class) ?? false,
            'can_restore_clients' => $request->user()?->can('restoreAny', Client::class) ?? false,
        ]);
    }

    public function store(
        Tenant $tenant,
        StoreDossierRequest $request,
        CreateDossierAction $createDossier,
    ): RedirectResponse {
        $dossier = $createDossier->handle($request->validated());

        return to_route(
            'workspaces.dossiers.show',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }

    public function show(
        Tenant $tenant,
        Request $request,
        Dossier $dossier,
        FetchDossierShowQuery $fetchDossierShow,
        LogAuditActivityAction $logAuditActivity,
    ): Response {
        $this->authorize('view', $dossier);

        if (! $this->isDossierPartialReload($request, 'dossiers/show')) {
            $logAuditActivity->handle(
                AuditEvent::DossierViewed,
                $dossier,
            );
        }

        return Inertia::render('dossiers/show', $fetchDossierShow->handle($request, $dossier));
    }

    /**
     * Full-bleed form builder for the dossier questionnaire.
     */
    public function builder(
        Tenant $tenant,
        Request $request,
        Dossier $dossier,
        FetchDossierBuilderQuery $fetchDossierBuilder,
    ): Response {
        $this->authorize('view', $dossier);

        return Inertia::render('dossiers/builder', $fetchDossierBuilder->handle($request, $dossier));
    }

    /**
     * Focused review queue for submitted questionnaire items.
     */
    public function review(
        Tenant $tenant,
        Request $request,
        Dossier $dossier,
        FetchDossierReviewQuery $fetchDossierReview,
    ): Response {
        $this->authorize('view', $dossier);

        return Inertia::render('dossiers/review', $fetchDossierReview->handle($request, $dossier));
    }

    public function edit(
        Tenant $tenant,
        Dossier $dossier,
        FetchDossierEditQuery $fetchDossierEdit,
    ): Response {
        $this->authorize('update', $dossier);

        return Inertia::render('dossiers/edit', $fetchDossierEdit->handle($tenant, $dossier));
    }

    public function update(
        Tenant $tenant,
        UpdateDossierRequest $request,
        Dossier $dossier,
        UpdateDossierAction $updateDossier,
    ): RedirectResponse {
        $updateDossier->handle($dossier, $request->validated());

        Toast::success(__('Dossier details updated.'));

        return to_route(
            'workspaces.dossiers.show',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }

    public function destroy(
        Tenant $tenant,
        Dossier $dossier,
        DeleteDossierAction $deleteDossier,
    ): RedirectResponse {
        $this->authorize('delete', $dossier);

        $user = $this->currentUser();

        $deleteDossier->handle($dossier, $user);

        Toast::success(__('Dossier archived.'));

        return to_route(
            'workspaces.dossiers.index',
            $this->workspaceRouteParameters(),
        );
    }

    public function restore(
        Tenant $tenant,
        Dossier $dossier,
        RestoreDossierAction $restoreDossier,
    ): RedirectResponse {
        $this->authorize('restore', $dossier);

        $user = $this->currentUser();

        $restoreDossier->handle($dossier, $user);

        Toast::success(__('Dossier restored. Create a new portal link before inviting the client again.'));

        return to_route(
            'workspaces.dossiers.show',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }

    public function bulkArchive(
        Tenant $tenant,
        BulkArchiveDossiersRequest $request,
        BulkDeleteDossiersAction $bulkDeleteDossiers,
    ): RedirectResponse {
        $user = $this->currentUser();

        $count = $bulkDeleteDossiers->handle($request->dossierIds(), $user);

        Toast::success($count === 1
            ? __('1 dossier archived.')
            : __(':count dossiers archived.', ['count' => $count]));

        return to_route(
            'workspaces.dossiers.index',
            $this->workspaceRouteParameters(),
        );
    }

    public function bulkRestore(
        Tenant $tenant,
        BulkRestoreDossiersRequest $request,
        BulkRestoreDossiersAction $bulkRestoreDossiers,
    ): RedirectResponse {
        $user = $this->currentUser();

        $count = $bulkRestoreDossiers->handle($request->dossierIds(), $user);

        Toast::success($count === 1
            ? __('1 dossier restored. Create a new portal link before inviting the client again.')
            : __(':count dossiers restored. Create a new portal link before inviting the client again.', ['count' => $count]));

        return to_route(
            'workspaces.dossiers.archived',
            $this->workspaceRouteParameters(),
        );
    }

    private function isDossierPartialReload(Request $request, string $component): bool
    {
        return $request->headers->has(Header::INERTIA)
            && $request->header(Header::PARTIAL_COMPONENT) === $component;
    }
}
