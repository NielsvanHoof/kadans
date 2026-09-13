<?php

declare(strict_types=1);

use App\Reporting\Http\Controllers\ActivityLog\ActivityLogController;
use App\Reporting\Http\Controllers\Dashboard\DashboardController;
use App\Reporting\Http\Controllers\DocumentSearch\DocumentSearchController;
use App\Reporting\Http\Controllers\MediaLibrary\MediaLibraryController;
use App\Reporting\Http\Controllers\Pipelines\PipelineController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');

Route::get('document-search', [DocumentSearchController::class, 'index'])
    ->middleware('throttle:60,1')
    ->name('document-search.index');
Route::post('document-search', [DocumentSearchController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('document-search.store');

Route::get('media', [MediaLibraryController::class, 'index'])->name('media.index');

Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');

Route::get('pipelines', [PipelineController::class, 'index'])->name('pipelines.index');
Route::get('pipelines/{pipeline}', [PipelineController::class, 'show'])->name('pipelines.show');
// Export archives contain client documents, so re-confirm the password first.
Route::get('pipelines/{pipeline}/download', [PipelineController::class, 'download'])
    ->middleware([
        RequirePassword::using(
            passwordTimeoutSeconds: Config::integer('auth.document_access_timeout', 900),
        ),
    ])
    ->name('pipelines.download');
// No RequirePassword here, unlike the download above: a retry streams no
// document content, it only re-queues work the user can already see.
Route::post('pipelines/{pipeline}/retry', [PipelineController::class, 'retry'])
    ->middleware(['throttle:10,1'])
    ->name('pipelines.retry');
// Cancelling closes a run the user can already see; same reasoning as retry.
Route::post('pipelines/{pipeline}/cancel', [PipelineController::class, 'cancel'])
    ->middleware(['throttle:10,1'])
    ->name('pipelines.cancel');
Route::delete('pipelines/{pipeline}', [PipelineController::class, 'destroy'])->name('pipelines.destroy');
