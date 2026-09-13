<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Tenancy\Actions\Roles\SyncTenantRolesAction;
use Illuminate\Database\Seeder;

final class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Creates the global permission rows and reconciles the roles of any firms
     * that already exist.
     */
    public function run(): void
    {
        resolve(SyncTenantRolesAction::class)->handle();
    }
}
