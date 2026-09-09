<?php

declare(strict_types=1);

use App\Tenancy\Http\Controllers\Settings\WorkspaceSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('settings', [WorkspaceSettingsController::class, 'index'])
    ->name('settings.index');

Route::patch('settings', [WorkspaceSettingsController::class, 'update'])
    ->name('settings.update');

// Separate from the policy settings above: this writes the firm record rather
// than its settings row, and changes the address the redirect lands on.
Route::patch('settings/firm', [WorkspaceSettingsController::class, 'updateFirm'])
    ->name('settings.firm.update');
