<?php

declare(strict_types=1);

use App\WorkspaceNotifications\Http\Controllers\WorkspaceNotificationController;
use Illuminate\Support\Facades\Route;

// Static segments before `{notification}` so they are not captured as an id.
Route::post('notifications/read-all', [WorkspaceNotificationController::class, 'store'])
    ->name('notifications.read-all');

Route::delete('notifications/clear-all', [WorkspaceNotificationController::class, 'destroyAll'])
    ->name('notifications.clear-all');

Route::post('notifications/{notification}/read', [WorkspaceNotificationController::class, 'update'])
    ->name('notifications.read');

Route::delete('notifications/{notification}', [WorkspaceNotificationController::class, 'destroy'])
    ->name('notifications.destroy');
