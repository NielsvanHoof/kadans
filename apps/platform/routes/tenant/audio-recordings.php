<?php

declare(strict_types=1);

use App\Dossiers\Http\Controllers\AudioRecordingController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

/*
 * Nested dossier → audio-recording create uses a scoped binding so the
 * dossier the file is stored under always matches the route.
 */
Route::scopeBindings()->group(function (): void {
    Route::post('dossiers/{dossier}/audio-recordings', [AudioRecordingController::class, 'store'])
        ->name('dossiers.audio-recordings.store');
});

// Not nested under a dossier — BelongsToTenant global scope is enough.
// Live status has no password gate so the dossier panel can refresh without re-confirming.
Route::get('audio-recordings/{audioRecording}/live', [AudioRecordingController::class, 'live'])
    ->name('audio-recordings.live');
Route::get('audio-recordings/{audioRecording}', [AudioRecordingController::class, 'show'])
    ->middleware(RequirePassword::using(
        passwordTimeoutSeconds: Config::integer('auth.document_access_timeout', 900),
    ))
    ->name('audio-recordings.show');
Route::get('audio-recordings/{audioRecording}/download', [AudioRecordingController::class, 'download'])
    ->middleware(RequirePassword::using(
        passwordTimeoutSeconds: Config::integer('auth.document_access_timeout', 900),
    ))
    ->name('audio-recordings.download');
Route::delete('audio-recordings/{audioRecording}', [AudioRecordingController::class, 'destroy'])
    ->name('audio-recordings.destroy');
