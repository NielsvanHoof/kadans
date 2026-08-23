<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Production-safe baseline seed (roles + missing system questionnaire templates).
 *
 * Safe to re-run on Cloud/staging/production: does not create users/tenants
 * and does not overwrite existing system templates. For local demo data use
 * LocalDatabaseSeeder instead.
 */
final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SystemQuestionnaireTemplateSeeder::class,
        ]);
    }
}
