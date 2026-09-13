<?php

declare(strict_types=1);

use App\Dossiers\Http\Controllers\DocumentRequests\DocumentRequestController;
use App\Dossiers\Http\Controllers\DocumentRequests\DocumentRequestReviewController;
use App\Dossiers\Http\Controllers\UploadedDocuments\UploadedDocumentController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

/*
 * Nested dossier → document-request routes use scoped bindings so a
 * document request ID from another dossier returns 404 automatically.
 */
Route::scopeBindings()->group(function (): void {
    Route::post('dossiers/{dossier}/document-requests', [DocumentRequestController::class, 'store'])
        ->name('dossiers.document-requests.store');
    // Static paths before {documentRequest} so "sync" is not treated as an id.
    Route::put('dossiers/{dossier}/document-requests/sync', [DocumentRequestController::class, 'sync'])
        ->name('dossiers.document-requests.sync');
    Route::post('dossiers/{dossier}/document-requests/reorder', [DocumentRequestController::class, 'reorder'])
        ->name('dossiers.document-requests.reorder');
    Route::put('dossiers/{dossier}/document-requests/{documentRequest}', [DocumentRequestController::class, 'update'])
        ->name('dossiers.document-requests.update');
    Route::delete('dossiers/{dossier}/document-requests/{documentRequest}', [DocumentRequestController::class, 'destroy'])
        ->name('dossiers.document-requests.destroy');
    Route::post(
        'dossiers/{dossier}/document-requests/{documentRequest}/answer',
        [DocumentRequestReviewController::class, 'answer'],
    )->name('dossiers.document-requests.answer');
    Route::post(
        'dossiers/{dossier}/document-requests/{documentRequest}/upload',
        [UploadedDocumentController::class, 'store'],
    )->name('dossiers.document-requests.upload');
    Route::post(
        'dossiers/{dossier}/document-requests/{documentRequest}/review',
        [DocumentRequestReviewController::class, 'store'],
    )->name('dossiers.document-requests.review');
});

// Not nested under a dossier — BelongsToTenant global scope is enough.
// Live status has no password gate so review can refresh OCR without re-confirming.
Route::get('uploaded-documents/{uploadedDocument}/live', [UploadedDocumentController::class, 'live'])
    ->name('uploaded-documents.live');
Route::get('uploaded-documents/{uploadedDocument}', [UploadedDocumentController::class, 'show'])
    ->middleware(RequirePassword::using(
        passwordTimeoutSeconds: Config::integer('auth.document_access_timeout', 900),
    ))
    ->name('uploaded-documents.show');
Route::get('uploaded-documents/{uploadedDocument}/preview', [UploadedDocumentController::class, 'preview'])
    ->middleware(RequirePassword::using(
        passwordTimeoutSeconds: Config::integer('auth.document_access_timeout', 900),
    ))
    ->name('uploaded-documents.preview');
Route::get('uploaded-documents/{uploadedDocument}/download', [UploadedDocumentController::class, 'download'])
    ->middleware(RequirePassword::using(
        passwordTimeoutSeconds: Config::integer('auth.document_access_timeout', 900),
    ))
    ->name('uploaded-documents.download');
Route::delete('uploaded-documents/{uploadedDocument}', [UploadedDocumentController::class, 'destroy'])
    ->name('uploaded-documents.destroy');
