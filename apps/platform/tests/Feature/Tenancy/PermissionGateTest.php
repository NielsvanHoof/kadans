<?php

declare(strict_types=1);

use App\Dossiers\Enums\DossierPermission;
use App\Dossiers\Models\Dossier;
use App\Pipelines\Models\Pipeline;
use App\Tenancy\Enums\Role;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

test('does not register Spatie permission checks for every Gate ability', function (): void {
    // Spatie's Gate::before looks up viewAny as a permission name. Tenancy owns
    // the before callback so policy abilities skip the vendor exception.
    expect(config('permission.register_permission_check_method'))->toBeFalse();
});

test('policy abilities do not look up a Spatie permission named after the method', function (): void {
    ['owner' => $owner] = provisionWorkspace();

    $warnings = [];
    Log::listen(function (MessageLogged $message) use (&$warnings): void {
        if ($message->level === 'warning') {
            $warnings[] = $message->message;
        }
    });

    expect(Gate::forUser($owner)->allows('viewAny', Dossier::class))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('viewAny', Pipeline::class))->toBeTrue()
        ->and(collect($warnings)
            ->filter(fn (string $message): bool => str_contains($message, 'There is no permission named')))
        ->toBeEmpty();
});

test('registered permission strings still resolve through the Gate', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $readOnly = workspaceMember($tenant, Role::ReadOnly);

    expect($owner->can(DossierPermission::View->value))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('viewAny', Dossier::class))->toBeTrue()
        ->and($readOnly->can(DossierPermission::View->value))->toBeTrue()
        ->and($readOnly->can(DossierPermission::Delete->value))->toBeFalse();
});
