<?php

declare(strict_types=1);

use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('integer workspace route params 404 on non-numeric segments instead of querying postgres', function (): void {
    // Route::pattern('[0-9]+') in routes/tenant.php must reject "not-a-number"
    // before Eloquent binding — otherwise bigint cast raises SQLSTATE 22P02.
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace('Acme Accountants');

    $this->actingAs($owner)
        ->get("/workspaces/{$tenant->slug}/clients/not-a-number")
        ->assertNotFound();

    $this->get("/workspaces/{$tenant->slug}/dossiers/not-a-number")
        ->assertNotFound();

    $this->get("/workspaces/{$tenant->slug}/pipelines/not-a-number")
        ->assertNotFound();
});
