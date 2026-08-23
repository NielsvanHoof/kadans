<?php

declare(strict_types=1);

use App\Questionnaires\Models\QuestionnaireTemplate;
use Database\Seeders\LocalDatabaseSeeder;
use Spatie\Permission\Models\Permission;

covers(LocalDatabaseSeeder::class);

test('local database seeder runs the production-safe baseline in local or testing', function (): void {
    expect(app()->environment())->toBeIn(['local', 'testing']);

    $this->seed(LocalDatabaseSeeder::class);

    expect(Permission::query()->count())->toBeGreaterThan(0)
        ->and(QuestionnaireTemplate::query()->whereNull('tenant_id')->count())->toBeGreaterThan(0);
});

test('local database seeder refuses to run outside local and testing', function (): void {
    $previous = app()->environment();

    // Temporarily claim a Cloud-like environment.
    app()->detectEnvironment(fn (): string => 'staging');

    try {
        expect(fn () => (new LocalDatabaseSeeder)->run())
            ->toThrow(RuntimeException::class, 'LocalDatabaseSeeder can only run');
    } finally {
        app()->detectEnvironment(fn (): string => $previous);
    }
});
