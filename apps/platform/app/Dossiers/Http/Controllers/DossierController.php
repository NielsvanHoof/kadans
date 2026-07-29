<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Clients\Models\Client;
use App\Dossiers\Actions\BulkDeleteDossiersAction;
use App\Dossiers\Actions\BulkRestoreDossiersAction;
use App\Dossiers\Actions\CreateDossierAction;
use App\Dossiers\Actions\DeleteDossierAction;
use App\Dossiers\Actions\RestoreDossierAction;
use App\Dossiers\Actions\UpdateDossierAction;
use App\Dossiers\Data\QuestionnaireTemplateOptionData;
use App\Dossiers\Enums\DocumentRequestPermission;
use App\Dossiers\Enums\UploadedDocumentPermission;
use App\Dossiers\Http\Requests\BulkArchiveDossiersRequest;
use App\Dossiers\Http\Requests\BulkRestoreDossiersRequest;
use App\Dossiers\Http\Requests\StoreDossierRequest;
use App\Dossiers\Http\Requests\UpdateDossierRequest;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Queries\FetchArchivedDossiersQuery;
use App\Dossiers\Queries\FetchDossierCreateQuery;
use App\Dossiers\Queries\FetchDossierEditQuery;
use App\Dossiers\Queries\FetchDossierIndexQuery;
use App\Dossiers\Queries\FetchDossierShowQuery;
use App\Questionnaires\Queries\FetchQuestionnaireTemplateOptionsQuery;
use App\Shared\Data\PersonOptionData;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\FetchResponsibleStaffOptionsQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

use function is_string;

final class DossierController extends Controller
{
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
        FetchDossierShowQuery $getDossierShowData,
        FetchQuestionnaireTemplateOptionsQuery $fetchQuestionnaireTemplateOptions,
        LogAuditActivityAction $logAuditActivity,
    ): Response {
        $this->authorize('view', $dossier);

        if (! $this->isDossierPartialReload($request, 'dossiers/show')) {
            $logAuditActivity->handle(
                AuditEvent::DossierViewed,
                $dossier,
            );
        }

        return Inertia::render(
            'dossiers/show',
            $this->dossierPageProps(
                $request,
                $dossier,
                $getDossierShowData,
                $fetchQuestionnaireTemplateOptions,
            ),
        );
    }

    /**
     * Full-bleed form builder for the dossier questionnaire.
     */
    public function builder(
        Tenant $tenant,
        Request $request,
        Dossier $dossier,
        FetchDossierShowQuery $getDossierShowData,
        FetchQuestionnaireTemplateOptionsQuery $fetchQuestionnaireTemplateOptions,
    ): Response {
        $this->authorize('view', $dossier);

        return Inertia::render(
            'dossiers/builder',
            $this->dossierPageProps(
                $request,
                $dossier,
                $getDossierShowData,
                $fetchQuestionnaireTemplateOptions,
            ),
        );
    }

    /**
     * Focused review queue for submitted questionnaire items.
     */
    public function review(
        Tenant $tenant,
        Request $request,
        Dossier $dossier,
        FetchDossierShowQuery $getDossierShowData,
    ): Response {
        $this->authorize('view', $dossier);

        return Inertia::render(
            'dossiers/review',
            $this->dossierPageProps(
                $request,
                $dossier,
                $getDossierShowData,
                includeTemplates: false,
            ),
        );
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

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Dossier details updated.'),
        ]);

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

        $user = request()->user();

        abort_unless($user instanceof User, HttpResponse::HTTP_FORBIDDEN);

        $deleteDossier->handle($dossier, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Dossier archived.'),
        ]);

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

        $user = request()->user();

        abort_unless($user instanceof User, HttpResponse::HTTP_FORBIDDEN);

        $restoreDossier->handle($dossier, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Dossier restored. Create a new portal link before inviting the client again.'),
        ]);

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
        $user = $request->user();

        abort_unless($user instanceof User, HttpResponse::HTTP_FORBIDDEN);

        $dossiersQuery = Dossier::query()->with('tenant');
        $dossiersQuery->getQuery()->whereIn('id', $request->dossierIds());
        $dossiers = $dossiersQuery->get();

        $count = $bulkDeleteDossiers->handle($dossiers, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $count === 1
                ? __('1 dossier archived.')
                : __(':count dossiers archived.', ['count' => $count]),
        ]);

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
        $user = $request->user();

        abort_unless($user instanceof User, HttpResponse::HTTP_FORBIDDEN);

        $dossiersQuery = Dossier::onlyTrashed()->with('tenant');
        $dossiersQuery->getQuery()->whereIn('id', $request->dossierIds());
        $dossiers = $dossiersQuery->get();

        $count = $bulkRestoreDossiers->handle($dossiers, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $count === 1
                ? __('1 dossier restored. Create a new portal link before inviting the client again.')
                : __(':count dossiers restored. Create a new portal link before inviting the client again.', ['count' => $count]),
        ]);

        return to_route(
            'workspaces.dossiers.archived',
            $this->workspaceRouteParameters(),
        );
    }

    /**
     * Shared Inertia props for overview, builder, and review surfaces.
     *
     * @return array<string, mixed>
     */
    private function dossierPageProps(
        Request $request,
        Dossier $dossier,
        FetchDossierShowQuery $getDossierShowData,
        ?FetchQuestionnaireTemplateOptionsQuery $fetchQuestionnaireTemplateOptions = null,
        bool $includeTemplates = true,
    ): array {
        $props = [
            'access_grant_token' => fn (): ?string => $this->flashedAccessGrantToken($request),
            'access_grant_portal_url' => fn (): ?string => $this->flashedAccessGrantPortalUrl($request),
            'can_manage' => $request->user()?->can('create', DocumentRequest::class) ?? false,
            'can_edit' => $request->user()?->can('update', $dossier) ?? false,
            'can_send_reminder' => $request->user()?->can('sendReminder', $dossier) ?? false,
            'can_review' => $request->user()?->hasTenantPermission(DocumentRequestPermission::Review) ?? false,
            'can_download' => $request->user()?->hasTenantPermission(UploadedDocumentPermission::Download) ?? false,
            'dossier' => fn (): array => $getDossierShowData->handle(
                $dossier,
                $request->user()?->can('export', $dossier) ?? false,
            )->toArray(),
        ];

        if ($includeTemplates && $fetchQuestionnaireTemplateOptions instanceof FetchQuestionnaireTemplateOptionsQuery) {
            $props['templates'] = fn (): array => array_map(
                static fn (QuestionnaireTemplateOptionData $template): array => $template->toArray(),
                $fetchQuestionnaireTemplateOptions->handle(),
            );
        }

        return $props;
    }

    private function isDossierPartialReload(Request $request, string $component): bool
    {
        return $request->headers->has(Header::INERTIA)
            && $request->header(Header::PARTIAL_COMPONENT) === $component;
    }

    private function flashedAccessGrantToken(Request $request): ?string
    {
        $token = $request->session()->pull('access_grant_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function flashedAccessGrantPortalUrl(Request $request): ?string
    {
        $url = $request->session()->pull('access_grant_portal_url');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
