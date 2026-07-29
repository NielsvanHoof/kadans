<?php

declare(strict_types=1);

use App\Reporting\Http\Controllers\ActivityLogController;
use App\Reporting\Http\Controllers\DashboardController;
use App\Reporting\Http\Controllers\MediaLibraryController;
use App\Reporting\Http\Controllers\PipelineController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');

Route::get('media', [MediaLibraryController::class, 'index'])->name('media.index');

Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');

Route::get('pipelines', [PipelineController::class, 'index'])->name('pipelines.index');
Route::get('pipelines/{pipeline}', [PipelineController::class, 'show'])->name('pipelines.show');
// Export archives contain client documents, so re-confirm the password first.
Route::get('pipelines/{pipeline}/download', [PipelineController::class, 'download'])
    ->middleware([
        RequirePassword::using(
            passwordTimeoutSeconds: (int) config('auth.document_access_timeout', 900),
        ),
    ])
    ->name('pipelines.download');
Route::delete('pipelines/{pipeline}', [PipelineController::class, 'destroy'])->name('pipelines.destroy');
