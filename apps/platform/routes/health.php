<?php

declare(strict_types=1);

use App\Platform\Http\Controllers\ReadinessController;
use App\Platform\Http\Middleware\EnsurePlatformSupport;
use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\HealthCheckJsonResultsController;

Route::get('ready', ReadinessController::class)->name('ready');

Route::middleware(['web', 'auth', EnsurePlatformSupport::class])
    ->get('health', HealthCheckJsonResultsController::class)
    ->name('health.details');
