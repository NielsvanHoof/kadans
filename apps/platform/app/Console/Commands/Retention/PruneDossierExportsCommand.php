<?php

declare(strict_types=1);

namespace App\Console\Commands\Retention;

use App\Actions\Pipelines\DeletePipelinesForSubjectsAction;
use App\Actions\Retention\DeleteStoredDossierExportFilesAction;
use App\Models\DossierExport;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('retention:prune-dossier-exports {--limit=500 : Maximum exports to prune per tenant}')]
#[Description('Delete expired dossier export archives and records')]
final class PruneDossierExportsCommand extends Command
{
    public function handle(
        TenantContext $tenantContext,
        DeleteStoredDossierExportFilesAction $deleteStoredFiles,
        DeletePipelinesForSubjectsAction $deletePipelines,
    ): int {
        $cutoff = now();
        $limit = max(1, (int) $this->option('limit'));
        $deleted = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($tenantContext, $deleteStoredFiles, $deletePipelines, $cutoff, $limit, &$deleted): void {
            $tenantContext->runForTenant($tenant, function () use ($deleteStoredFiles, $deletePipelines, $cutoff, $limit, &$deleted): void {
                $query = DossierExport::query();
                $query->getQuery()
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', $cutoff)
                    ->limit($limit);
                $exports = $query->get();

                if ($exports->isEmpty()) {
                    return;
                }

                $deleteStoredFiles->handle($exports);
                $deletePipelines->handle($exports);
                $deleted += DossierExport::query()->whereKey($exports->modelKeys())->delete();
            });
        });

        $this->components->info(sprintf('Deleted %d expired dossier export(s).', $deleted));

        return self::SUCCESS;
    }
}
