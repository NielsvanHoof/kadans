<?php

declare(strict_types=1);

use App\Tenancy\Http\Controllers\Roles\WorkspaceRoleController;
use Illuminate\Support\Facades\Route;

Route::get('roles', [WorkspaceRoleController::class, 'index'])
    ->name('roles.index');

Route::get('roles/manage', [WorkspaceRoleController::class, 'create'])
    ->name('roles.manage');

Route::post('roles', [WorkspaceRoleController::class, 'store'])
    ->name('roles.store');

Route::patch('roles/{role}', [WorkspaceRoleController::class, 'update'])
    ->name('roles.update');

Route::delete('roles/{role}', [WorkspaceRoleController::class, 'destroy'])
    ->name('roles.destroy');
