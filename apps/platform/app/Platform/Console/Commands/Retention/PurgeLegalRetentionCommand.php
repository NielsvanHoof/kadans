<?php

declare(strict_types=1);

namespace App\Platform\Console\Commands\Retention;

use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Platform\Actions\Retention\PurgeExpiredClientAction;
use App\Platform\Actions\Retention\PurgeExpiredDossierAction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

use function Laravel\Prompts\info;
use function sprintf;

#[Signature('retention:purge-legal {--limit=50 : Maximum records to purge per model type and tenant}')]
#[Description('Permanently purge archived clients and dossiers past the legal retention period')]
final class PurgeLegalRetentionCommand extends Command
{
    public function handle(
        TenantContext $tenantContext,
        PurgeExpiredDossierAction $purgeExpiredDossier,
        PurgeExpiredClientAction $purgeExpiredClient,
    ): int {
        $limit = max(1, (int) $this->option('limit'));
        $cutoff = now()->subDays(Config::integer('retention.archived_days'));
        $dossierSummary = [
            'purged' => 0,
            'activity_rows_deleted' => 0,
            'files_deleted' => 0,
        ];
        $clientSummary = [
            'purged' => 0,
            'activity_rows_deleted' => 0,
        ];

        Tenant::query()->each(function (Tenant $tenant) use (
            $tenantContext,
            $purgeExpiredDossier,
            $purgeExpiredClient,
            $cutoff,
            $limit,
            &$dossierSummary,
            &$clientSummary,
        ): void {
            $tenantContext->runForTenant($tenant, function () use (
                $purgeExpiredDossier,
                $purgeExpiredClient,
                $cutoff,
                $limit,
                &$dossierSummary,
                &$clientSummary,
            ): void {
                $tenantDossierSummary = $this->purgeDossiers($purgeExpiredDossier, $cutoff, $limit);
                $tenantClientSummary = $this->purgeClients($purgeExpiredClient, $cutoff, $limit);

                $dossierSummary['purged'] += $tenantDossierSummary['purged'];
                $dossierSummary['activity_rows_deleted'] += $tenantDossierSummary['activity_rows_deleted'];
                $dossierSummary['files_deleted'] += $tenantDossierSummary['files_deleted'];
                $clientSummary['purged'] += $tenantClientSummary['purged'];
                $clientSummary['activity_rows_deleted'] += $tenantClientSummary['activity_rows_deleted'];
            });
        });

        Log::info('Legal retention purge completed.', [
            'cutoff' => $cutoff->toIso8601String(),
            'dossiers_purged' => $dossierSummary['purged'],
            'clients_purged' => $clientSummary['purged'],
            'activity_rows_deleted' => $dossierSummary['activity_rows_deleted'] + $clientSummary['activity_rows_deleted'],
            'files_deleted' => $dossierSummary['files_deleted'],
        ]);

        info(sprintf(
            'Purged %d dossier(s), %d client(s), deleted %d activity row(s) and %d file(s).',
            $dossierSummary['purged'],
            $clientSummary['purged'],
            $dossierSummary['activity_rows_deleted'] + $clientSummary['activity_rows_deleted'],
            $dossierSummary['files_deleted'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{purged: int, activity_rows_deleted: int, files_deleted: int}
     */
    private function purgeDossiers(PurgeExpiredDossierAction $purgeExpiredDossier, CarbonInterface $cutoff, int $limit): array
    {
        $summary = [
            'purged' => 0,
            'activity_rows_deleted' => 0,
            'files_deleted' => 0,
        ];

        $dossiersQuery = Dossier::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff);
        $dossiersQuery->getQuery()->orderBy('id')->limit($limit);
        $dossiers = $dossiersQuery->get();

        foreach ($dossiers as $dossier) {
            $result = $purgeExpiredDossier->handle($dossier);

            if (! $result['purged']) {
                continue;
            }

            $summary['purged']++;
            $summary['activity_rows_deleted'] += $result['activity_rows_deleted'];
            $summary['files_deleted'] += $result['files_deleted'];
        }

        return $summary;
    }

    /**
     * @return array{purged: int, activity_rows_deleted: int}
     */
    private function purgeClients(PurgeExpiredClientAction $purgeExpiredClient, CarbonInterface $cutoff, int $limit): array
    {
        $summary = [
            'purged' => 0,
            'activity_rows_deleted' => 0,
        ];

        // Soft-deleted dossiers still block purge; toBase() subquery includes them.
        $clientsQuery = Client::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff);
        $clientsQuery->getQuery()
            ->whereNotIn('id', Dossier::withTrashed()->toBase()->select('client_id'))
            ->orderBy('id')
            ->limit($limit);
        $clients = $clientsQuery->get();

        foreach ($clients as $client) {
            $result = $purgeExpiredClient->handle($client);

            if (! $result['purged']) {
                continue;
            }

            $summary['purged']++;
            $summary['activity_rows_deleted'] += $result['activity_rows_deleted'];
        }

        return $summary;
    }
}
