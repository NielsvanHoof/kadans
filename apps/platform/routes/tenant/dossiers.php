<?php

declare(strict_types=1);

use App\Dossiers\Http\Controllers\DocumentRequestController;
use App\Dossiers\Http\Controllers\DossierCompletionController;
use App\Dossiers\Http\Controllers\DossierController;
use App\Dossiers\Http\Controllers\DossierExportController;
use App\Dossiers\Http\Controllers\DossierReminderController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::get('dossiers', [DossierController::class, 'index'])->name('dossiers.index');
Route::get('dossiers/archived', [DossierController::class, 'archived'])->name('dossiers.archived');
Route::get('dossiers/create', [DossierController::class, 'create'])->name('dossiers.create');
Route::post('dossiers', [DossierController::class, 'store'])->name('dossiers.store');
// Bulk routes before {dossier} so "bulk-archive" is not treated as an id.
Route::post('dossiers/bulk-archive', [DossierController::class, 'bulkArchive'])
    ->name('dossiers.bulk-archive');
Route::post('dossiers/bulk-restore', [DossierController::class, 'bulkRestore'])
    ->name('dossiers.bulk-restore');
Route::get('dossiers/{dossier}', [DossierController::class, 'show'])->name('dossiers.show');
Route::get('dossiers/{dossier}/builder', [DossierController::class, 'builder'])
    ->name('dossiers.builder');
Route::get('dossiers/{dossier}/review', [DossierController::class, 'review'])
    ->name('dossiers.review');
Route::get('dossiers/{dossier}/edit', [DossierController::class, 'edit'])->name('dossiers.edit');
Route::patch('dossiers/{dossier}', [DossierController::class, 'update'])->name('dossiers.update');
Route::post('dossiers/{dossier}/reminders', [DossierReminderController::class, 'store'])
    ->name('dossiers.reminders.store');
Route::delete('dossiers/{dossier}', [DossierController::class, 'destroy'])->name('dossiers.destroy');
Route::patch('dossiers/{dossier}/restore', [DossierController::class, 'restore'])
    ->withTrashed()
    ->name('dossiers.restore');
Route::post('dossiers/{dossier}/complete', [DossierCompletionController::class, 'store'])
    ->name('dossiers.complete');
Route::post('dossiers/{dossier}/apply-template', [DocumentRequestController::class, 'applyTemplate'])
    ->name('dossiers.apply-template');

Route::scopeBindings()->group(function (): void {
    Route::post('dossiers/{dossier}/exports', [DossierExportController::class, 'store'])
        ->name('dossiers.exports.store');
    // Status island for Echo updates — download stays signed + password-gated.
    Route::get('dossiers/{dossier}/exports/{export}', [DossierExportController::class, 'show'])
        ->name('dossiers.exports.show');
    Route::get('dossiers/{dossier}/exports/{export}/download', [DossierExportController::class, 'download'])
        ->middleware([
            'signed',
            RequirePassword::using(
                passwordTimeoutSeconds: (int) config('auth.document_access_timeout', 900),
            ),
        ])
        ->name('dossiers.exports.download');
});
