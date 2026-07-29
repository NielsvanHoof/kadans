<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Policies;

use App\Accounts\Models\User;
use App\DocumentProcessing\Enums\PipelineType;
use App\DocumentProcessing\Models\Pipeline;
use App\Dossiers\Models\DossierExport;
use App\Tenancy\Enums\Permission;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class PipelinePolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, Permission::ViewAuditLog);
    }

    public function view(User $user, Pipeline $pipeline): bool
    {
        return $this->userHasPermissionInTenant($user, $pipeline->tenant, Permission::ViewAuditLog);
    }

    /**
     * Only export pipelines produce a downloadable payload; OCR runs have none.
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
            && $this->userHasPermissionInTenant($user, $pipeline->tenant, Permission::DownloadDocuments);
    }

    public function delete(User $user, Pipeline $pipeline): bool
    {
        return $pipeline->status->isTerminal()
            && $this->userHasPermissionInTenant($user, $pipeline->tenant, Permission::CreateDossiers);
    }
}
