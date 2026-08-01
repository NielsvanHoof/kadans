<?php

declare(strict_types=1);

namespace App\Dossiers\Queries;

use App\Accounts\Models\User;
use App\Dossiers\Actions\Builder\AcquireDossierBuilderLockAction;
use App\Dossiers\Data\DossierBuilderData;
use App\Dossiers\Data\DossierBuilderLockData;
use App\Dossiers\Data\QuestionnaireTemplateOptionData;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Queries\FetchQuestionnaireTemplateOptionsQuery;
use Illuminate\Http\Request;

/**
 * Lean Inertia prop bag for the dossier questionnaire builder page.
 */
final readonly class FetchDossierBuilderQuery
{
    public function __construct(
        private MapStaffDocumentRequestRowsQuery $mapStaffDocumentRequestRowsQuery,
        private FetchQuestionnaireTemplateOptionsQuery $fetchQuestionnaireTemplateOptions,
        private AcquireDossierBuilderLockAction $acquireDossierBuilderLock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, Dossier $dossier): array
    {
        $user = $request->user();
        $canManage = $user?->can('create', DocumentRequest::class) ?? false;
        $canUpdate = $user instanceof User && $user->can('update', $dossier);
        $isCompletable = $dossier->status !== DossierStatus::Completed;

        // Entering /builder claims the lock when the visitor can edit structure.
        $builderLock = $this->resolveBuilderLock(
            $dossier,
            $user instanceof User ? $user : null,
            $canManage && $canUpdate && $isCompletable,
        );

        return [
            'can_manage' => $canManage,
            'builder_lock' => $builderLock->toArray(),
            'templates' => fn (): array => array_map(
                static fn (QuestionnaireTemplateOptionData $template): array => $template->toArray(),
                $this->fetchQuestionnaireTemplateOptions->handle(),
            ),
            // Lazy so partial reloads can skip the questionnaire graph.
            'dossier' => fn (): array => $this->dossierPayload($dossier)->toArray(),
        ];
    }

    private function resolveBuilderLock(
        Dossier $dossier,
        ?User $user,
        bool $shouldAcquire,
    ): DossierBuilderLockData {
        if (! $user instanceof User) {
            return DossierBuilderLockData::fromDossier($dossier, null);
        }

        if ($shouldAcquire) {
            return $this->acquireDossierBuilderLock->handle($dossier, $user);
        }

        $dossier->loadMissing('builderLockedBy:id,name,email');

        return DossierBuilderLockData::fromDossier($dossier, $user);
    }

    private function dossierPayload(Dossier $dossier): DossierBuilderData
    {
        // Builder edits structure only — skip uploads, grants, and exports.
        $dossier->load([
            'documentRequests' => fn ($query) => $query
                ->with('reviewedBy:id,name')
                ->oldest('sort_order'),
        ]);

        $mapped = $this->mapStaffDocumentRequestRowsQuery->handle(
            $dossier->status,
            $dossier->documentRequests,
            includeUploadedDocuments: false,
        );

        return new DossierBuilderData(
            id: $dossier->id,
            title: $dossier->title,
            status: $dossier->status->value,
            documentRequests: $mapped->rows,
        );
    }
}
