<?php

declare(strict_types=1);

use App\Tenancy\Http\Controllers\WorkspaceSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('settings', [WorkspaceSettingsController::class, 'index'])
    ->name('settings.index');

Route::patch('settings', [WorkspaceSettingsController::class, 'update'])
    ->name('settings.update');
