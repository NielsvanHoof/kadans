<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local-only seeder. Never run on Cloud/staging/production.
 *
 * Usage: php artisan db:seed --class=LocalDatabaseSeeder
 *
 * Starts from the same production-safe baseline as DatabaseSeeder.
 * Demo clients/dossiers can be added here later — never under DatabaseSeeder.
 */
final class LocalDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        throw_unless(app()->environment(['local', 'testing']), RuntimeException::class, 'LocalDatabaseSeeder can only run when APP_ENV is local or testing.');

        $this->call(DatabaseSeeder::class);

        // Optional demo data (clients, dossiers, …) belongs here only.
    }
}
