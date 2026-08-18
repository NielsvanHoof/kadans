<?php

declare(strict_types=1);

use App\Platform\Http\Controllers\ReadinessController;
use App\Platform\Http\Middleware\EnsurePlatformSupport;
use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\HealthCheckJsonResultsController;

// Anonymous on purpose — load balancers probe this without a session.
// 60/min per IP sits well above typical ALB intervals (5–30s ≈ 2–12/min)
// while still capping unauthenticated DB/Redis work from a cheap GET.
Route::get('ready', ReadinessController::class)
    ->middleware('throttle:60,1')
    ->name('ready');

Route::middleware(['web', 'auth', EnsurePlatformSupport::class])
    ->get('health', HealthCheckJsonResultsController::class)
    ->name('health.details');
