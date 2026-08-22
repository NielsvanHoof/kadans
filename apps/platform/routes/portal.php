<?php

declare(strict_types=1);

use App\ClientPortal\Http\Controllers\ClientPortalAnswerController;
use App\ClientPortal\Http\Controllers\ClientPortalController;
use App\ClientPortal\Http\Controllers\ClientPortalEndedController;
use App\ClientPortal\Http\Controllers\ClientPortalSessionController;
use App\ClientPortal\Http\Controllers\ClientPortalSessionExtendController;
use App\ClientPortal\Http\Controllers\ClientPortalUploadController;
use App\ClientPortal\Http\Controllers\ClientPortalUploadTicketController;
use App\ClientPortal\Http\Middleware\HardenClientPortalResponse;
use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use Illuminate\Support\Facades\Route;

/* Guest client portal — magic links are exchanged for restricted sessions. */
Route::middleware([
    'web',
    'throttle:30,1',
    HardenClientPortalResponse::class,
])
    ->prefix('portal')
    ->name('portal.')
    ->group(function (): void {
        Route::get('access/{token}', ClientPortalSessionController::class)
            ->where('token', '[A-Za-z0-9]+')
            ->middleware('throttle:10,1')
            ->name('exchange');

        // Outside the resolver group on purpose: this is where the resolver
        // sends a client whose session ended, so running it here would loop.
        Route::get('ended', ClientPortalEndedController::class)
            ->name('ended');

        Route::middleware(ResolveClientPortalGrant::class)->group(function (): void {
            Route::get('/', [ClientPortalController::class, 'show'])
                ->name('show');

            // Signs a PUT into the staging prefix for the granted dossier's
            // tenant. Tighter throttle than the group default: this is the one
            // portal route an unattended client could call in a loop.
            Route::post('upload-tickets', [ClientPortalUploadTicketController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('upload-tickets.store');

            Route::post('document-requests/{documentRequest}/upload', [ClientPortalUploadController::class, 'store'])
                ->where('documentRequest', '[0-9]+')
                ->name('document-requests.upload');

            Route::delete('document-requests/{documentRequest}/uploaded-documents/{uploadedDocument}', [ClientPortalUploadController::class, 'destroy'])
                ->where('documentRequest', '[0-9]+')
                ->where('uploadedDocument', '[0-9]+')
                ->name('document-requests.uploaded-documents.destroy');

            // Deliberately no RequirePassword, unlike the staff routes that
            // stream bytes: a portal client has no account. The gate is the
            // hashed grant, the short session, and the malware verdict.
            Route::get('document-requests/{documentRequest}/uploaded-documents/{uploadedDocument}/download', [ClientPortalUploadController::class, 'download'])
                ->where('documentRequest', '[0-9]+')
                ->where('uploadedDocument', '[0-9]+')
                ->name('document-requests.uploaded-documents.download');

            Route::post('document-requests/{documentRequest}/answer', [ClientPortalAnswerController::class, 'store'])
                ->where('documentRequest', '[0-9]+')
                ->name('document-requests.answer');

            // Passing the resolver is what slides the idle deadline; the
            // controller only has to send the client back to the form.
            Route::post('session/extend', ClientPortalSessionExtendController::class)
                ->name('session.extend');
        });
    });
