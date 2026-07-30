<?php

declare(strict_types=1);

use App\Tenancy\Http\Controllers\WorkspaceRoleController;
use Illuminate\Support\Facades\Route;

Route::get('roles', [WorkspaceRoleController::class, 'index'])
    ->name('roles.index');
