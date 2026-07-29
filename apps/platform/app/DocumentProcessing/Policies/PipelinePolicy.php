<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Policies;

use App\Accounts\Models\User;
use App\DocumentProcessing\Enums\PipelinePermission;
use App\DocumentProcessing\Enums\PipelineType;
use App\DocumentProcessing\Models\Pipeline;
use App\Dossiers\Enums\DossierPermission;
use App\Dossiers\Models\DossierExport;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class PipelinePolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, PipelinePermission::View);
    }

    public function view(User $user, Pipeline $pipeline): bool
    {
        return $this->userHasPermissionInTenant($user, $pipeline->tenant, PipelinePermission::View);
    }

    /**
     * Only export pipelines produce a downloadable payload; OCR runs have none.
     * The archive is the product of a dossier export, so it is gated on that
     * permission rather than on pipeline visibility.
     */
    public function download(User $user, Pipeline $pipeline): bool
    {
        if ($pipeline->type !== PipelineType::DossierExport) {
            return false;
        }

        $pipeline->loadMissing('pipelineable');
        $export = $pipeline->pipelineable;

        return $export instanceof DossierExport
            && $export->isDownloadable()
            && $this->userHasPermissionInTenant($user, $pipeline->tenant, DossierPermission::Export);
    }

    public function delete(User $user, Pipeline $pipeline): bool
    {
        return $pipeline->status->isTerminal()
            && $this->userHasPermissionInTenant($user, $pipeline->tenant, PipelinePermission::Delete);
    }
}
