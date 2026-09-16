<?php

declare(strict_types=1);

use App\ClientPortal\Http\Controllers\Access\ClientAccessGrantController;
use App\ClientPortal\Http\Controllers\Access\ClientPortalLinkController;
use App\ClientPortal\Http\Controllers\Access\ShareDossierWithClientController;
use Illuminate\Support\Facades\Route;

Route::post('dossiers/{dossier}/access-grants', [ClientAccessGrantController::class, 'store'])
    ->name('dossiers.access-grants.store');
Route::post('clients/{client}/access-grants', [ClientPortalLinkController::class, 'store'])
    ->name('clients.access-grants.store');

// Publishing without minting a token: what puts a dossier behind a client's
// standing link.
Route::post('dossiers/{dossier}/share', [ShareDossierWithClientController::class, 'store'])
    ->name('dossiers.share.store');

// Both scopes revoke through the same endpoint — it is the same row.
Route::delete('access-grants/{grant}', [ClientAccessGrantController::class, 'destroy'])
    ->name('access-grants.destroy');
