<?php

declare(strict_types=1);

use App\Platform\Http\Controllers\DataSubject\DataSubjectRequestController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

Route::get('data-subject-requests', [DataSubjectRequestController::class, 'index'])
    ->name('data-subject-requests.index');

Route::post('data-subject-requests', [DataSubjectRequestController::class, 'store'])
    ->name('data-subject-requests.store');

Route::post('data-subject-requests/{dataSubjectRequest}/fulfil', [DataSubjectRequestController::class, 'fulfil'])
    ->where('dataSubjectRequest', '[0-9]+')
    ->name('data-subject-requests.fulfil');

Route::post('data-subject-requests/{dataSubjectRequest}/refuse', [DataSubjectRequestController::class, 'refuse'])
    ->where('dataSubjectRequest', '[0-9]+')
    ->name('data-subject-requests.refuse');

// The archive is every document the firm holds about one person, so it sits
// behind the same recent-password-confirmation gate as every document route.
Route::get('data-subject-requests/{dataSubjectRequest}/download', [DataSubjectRequestController::class, 'download'])
    ->where('dataSubjectRequest', '[0-9]+')
    ->middleware(RequirePassword::using(
        passwordTimeoutSeconds: Config::integer('auth.document_access_timeout', 900),
    ))
    ->name('data-subject-requests.download');
