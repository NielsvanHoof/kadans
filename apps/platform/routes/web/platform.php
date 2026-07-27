<?php

declare(strict_types=1);

use App\Http\Controllers\Platform\SupportSessionController;
use App\Http\Middleware\EnsurePlatformSupport;
use Illuminate\Support\Facades\Route;

Route::middleware([EnsurePlatformSupport::class])
    ->prefix('platform/support')
    ->name('platform.support.')
    ->group(function (): void {
        Route::get('/', [SupportSessionController::class, 'index'])
            ->name('index');
        Route::post('/{tenant:slug}', [SupportSessionController::class, 'store'])
            ->name('store');
        Route::delete('/', [SupportSessionController::class, 'destroy'])
            ->name('destroy');
    });
