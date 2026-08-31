<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

test('permission team columns reference tenants', function (): void {
    $roleKeys = collect(Schema::getForeignKeys('roles'))
        ->filter(fn (array $foreign): bool => $foreign['columns'] === ['tenant_id'])
        ->values();

    expect($roleKeys)->toHaveCount(1)
        ->and($roleKeys[0]['foreign_table'])->toBe('tenants')
        ->and($roleKeys[0]['foreign_columns'])->toBe(['id']);

    $modelHasRolesKeys = collect(Schema::getForeignKeys('model_has_roles'))
        ->filter(fn (array $foreign): bool => $foreign['columns'] === ['tenant_id'])
        ->values();

    expect($modelHasRolesKeys)->toHaveCount(1)
        ->and($modelHasRolesKeys[0]['foreign_table'])->toBe('tenants');

    $modelHasPermissionsKeys = collect(Schema::getForeignKeys('model_has_permissions'))
        ->filter(fn (array $foreign): bool => $foreign['columns'] === ['tenant_id'])
        ->values();

    expect($modelHasPermissionsKeys)->toHaveCount(1)
        ->and($modelHasPermissionsKeys[0]['foreign_table'])->toBe('tenants');
});
