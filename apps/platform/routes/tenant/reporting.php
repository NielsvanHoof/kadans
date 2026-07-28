<?php

declare(strict_types=1);

use App\Http\Controllers\Reporting\ActivityLogController;
use App\Http\Controllers\Reporting\DashboardController;
use App\Http\Controllers\Reporting\MediaLibraryController;
use App\Http\Controllers\Reporting\PipelineController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');

Route::get('media', [MediaLibraryController::class, 'index'])->name('media.index');

Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');

Route::get('pipelines', [PipelineController::class, 'index'])->name('pipelines.index');
Route::get('pipelines/{pipeline}', [PipelineController::class, 'show'])->name('pipelines.show');
Route::delete('pipelines/{pipeline}', [PipelineController::class, 'destroy'])->name('pipelines.destroy');
