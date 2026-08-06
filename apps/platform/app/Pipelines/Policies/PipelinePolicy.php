<?php

declare(strict_types=1);

namespace App\Pipelines\Policies;

use App\Accounts\Models\User;
use App\Dossiers\Enums\DossierPermission;
use App\Dossiers\Models\DossierExport;
use App\Pipelines\Enums\PipelinePermission;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

use function in_array;

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

    /**
     * Only a failed or stuck run may be restarted. This deliberately does not
     * use `isTerminal()` like `delete()` does: that also covers completed and
     * cancelled runs, which stay closed.
     */
    public function retry(User $user, Pipeline $pipeline): bool
    {
        return in_array($pipeline->status, [PipelineStatus::Failed, PipelineStatus::Stuck], true)
            && $this->userHasPermissionInTenant($user, $pipeline->tenant, PipelinePermission::Retry);
    }

    public function delete(User $user, Pipeline $pipeline): bool
    {
        return $pipeline->status->isTerminal()
            && $this->userHasPermissionInTenant($user, $pipeline->tenant, PipelinePermission::Delete);
    }
}
