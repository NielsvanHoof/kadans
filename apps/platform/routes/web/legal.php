<?php

declare(strict_types=1);

use App\Platform\Http\Controllers\Legal\LegalDocumentController;
use Illuminate\Support\Facades\Route;

/*
 * Public legal documents — available to guests, staff, and portal visitors.
 */
Route::get('/legal/{document}', [LegalDocumentController::class, 'show'])
    ->whereIn('document', ['privacy', 'cookies', 'terms'])
    ->name('legal.show');
