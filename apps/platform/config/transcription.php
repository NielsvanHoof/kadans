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

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | http — seconds for the provider HTTP call (Laravel AI ->timeout()).
    | job  — seconds for ProcessAudioRecordingJob::$timeout.
    |
    | Keep http < job, and REDIS_QUEUE_RETRY_AFTER > job, otherwise Redis can
    | re-dispatch a still-running transcription to a second worker.
    |
    */

    'timeouts' => [
        'http' => (int) env('TRANSCRIPTION_HTTP_TIMEOUT', 270),
        'job' => (int) env('TRANSCRIPTION_JOB_TIMEOUT', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local Audio Chunking
    |--------------------------------------------------------------------------
    |
    | Long recordings often exceed a single provider HTTP timeout. When enabled,
    | we probe duration with ffprobe, normalize to mono 16 kHz mp3, and split
    | into max_chunk_seconds pieces before calling the STT API.
    |
    | Speakers are remapped per chunk (cross-chunk identity is not preserved).
    |
    */

    'chunking' => [
        'enabled' => (bool) env('TRANSCRIPTION_CHUNKING_ENABLED', true),

        // Split when the recording is longer than this many seconds.
        'min_duration_seconds' => (int) env('TRANSCRIPTION_CHUNK_MIN_DURATION', 120),

        // Maximum length of each local chunk sent to the provider.
        'max_chunk_seconds' => (int) env('TRANSCRIPTION_MAX_CHUNK_SECONDS', 90),

        // Binary paths — override only if ffmpeg/ffprobe are not on PATH.
        'ffmpeg_binary' => env('TRANSCRIPTION_FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe_binary' => env('TRANSCRIPTION_FFPROBE_BINARY', 'ffprobe'),
    ],

];
