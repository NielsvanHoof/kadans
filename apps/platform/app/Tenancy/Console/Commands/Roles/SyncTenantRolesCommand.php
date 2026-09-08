<?php

declare(strict_types=1);

namespace App\Tenancy\Console\Commands\Roles;

use App\Tenancy\Actions\Roles\SyncTenantRolesAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

#[Signature('tenancy:sync-roles {--prune : Delete permissions no longer declared in code}')]
#[Description('Reconcile every firm\'s roles and permissions with what the code declares')]
final class SyncTenantRolesCommand extends Command
{
    public function handle(SyncTenantRolesAction $syncTenantRoles): int
    {
        $prune = $this->option('prune');

        info($prune
            ? 'Syncing roles and pruning undeclared permissions…'
            : 'Syncing roles and permissions…');

        $report = $syncTenantRoles->handle($prune);

        table(
            ['Metric', 'Count'],
            [
                ['Permissions created', (string) $report['permissions_created']],
                ['Permissions pruned', (string) $report['permissions_pruned']],
                ['Roles created', (string) $report['roles_created']],
                ['Firms synced', (string) $report['tenants_synced']],
            ],
        );

        if (! $prune) {
            info('Run with --prune to remove permissions the code no longer declares.');
        }

        return self::SUCCESS;
    }
}
