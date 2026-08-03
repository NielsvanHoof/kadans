<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Transcription Driver
    |--------------------------------------------------------------------------
    |
    | mock — sync fake transcript (CI / offline). Completes inside the upload job.
    | ai   — real speech-to-text via laravel/ai's Transcription class.
    |
    | Which real provider (OpenAI, ElevenLabs, Mistral, Gemini) to use is not
    | decided yet. Once it is, configure it via config/ai.php's
    | `default_for_transcription` and the matching provider API key env var —
    | this driver does not hardcode a provider.
    |
    */

    'driver' => env('TRANSCRIPTION_DRIVER', 'mock'),

];
