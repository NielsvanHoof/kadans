<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Transcription Driver
    |--------------------------------------------------------------------------
    |
    | mock       — sync fake transcript (CI / offline). Completes inside the job.
    | transcribe — async Amazon Transcribe via EventBridge → SQS.
    |
    | laravel/ai is not used for STT; keep the package for other AI features later.
    |
    */

    'driver' => env('TRANSCRIPTION_DRIVER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Job Timeout
    |--------------------------------------------------------------------------
    |
    | Seconds for ProcessAudioRecordingJob::$timeout. The start job is fast
    | (mock completes inline; Transcribe only starts the AWS job). Keep
    | REDIS_QUEUE_RETRY_AFTER above this value.
    |
    */

    'timeouts' => [
        'job' => (int) env('TRANSCRIPTION_JOB_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Amazon Transcribe (async)
    |--------------------------------------------------------------------------
    |
    | Completions arrive via EventBridge → SQS.
    | Credentials reuse AWS_* like OCR.
    |
    */

    'transcribe' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'token' => env('AWS_SESSION_TOKEN'),
        'region' => env('AWS_DEFAULT_REGION', 'eu-west-1'),
        // Local Floci/Sail only. Leave empty in production so the SDK uses real AWS.
        'endpoint' => env('AWS_ENDPOINT'),
        'bucket' => env('AWS_BUCKET'),
        'results_queue_url' => env('TRANSCRIBE_RESULTS_QUEUE_URL'),
        'output_prefix' => env('TRANSCRIBE_OUTPUT_PREFIX', 'transcriptions'),
        // Restrict auto-language detection to expected locales.
        'language_options' => array_values(array_filter(array_map(
            mb_trim(...),
            explode(',', (string) env('TRANSCRIBE_LANGUAGE_OPTIONS', 'nl-NL,en-US')),
        ))),
        'max_speaker_labels' => (int) env('TRANSCRIBE_MAX_SPEAKERS', 10),
        'sqs_wait_time_seconds' => (int) env('TRANSCRIBE_SQS_WAIT_TIME', 20),
        'sqs_max_messages' => (int) env('TRANSCRIBE_SQS_MAX_MESSAGES', 5),
        'sqs_max_receive_count' => (int) env('TRANSCRIBE_SQS_MAX_RECEIVE_COUNT', 5),
    ],

];
