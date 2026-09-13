<?php

declare(strict_types=1);

use App\Dossiers\Http\Controllers\Uploads\UploadTicketController;
use Illuminate\Support\Facades\Route;

/*
 * Presigned upload tickets for staff.
 *
 * Not nested under a dossier: a ticket only authorises a write into the
 * workspace's staging prefix, and which dossier the object ends up on is
 * decided when the ticket is spent (see ClaimUploadTicketAction). Nesting it
 * would imply an authorization boundary the ticket does not actually carry.
 *
 * Throttled because each request signs a URL and costs nothing to ask for.
 */
Route::post('upload-tickets', [UploadTicketController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('upload-tickets.store');
