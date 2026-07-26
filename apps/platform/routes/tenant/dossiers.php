<?php

declare(strict_types=1);

use App\Http\Controllers\Dossiers\DocumentRequestController;
use App\Http\Controllers\Dossiers\DossierCompletionController;
use App\Http\Controllers\Dossiers\DossierController;
use App\Http\Controllers\Dossiers\DossierReminderController;
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
