<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Malware scan results and document thumbnails
    |--------------------------------------------------------------------------
    |
    | GuardDuty scans every object landing in the documents bucket. The verdict
    | reaches this application two ways, and they are not interchangeable:
    |
    |  - as a tag on the object, read at delivery time by
    |    ResolveMalwareScanStatusAction. That is the authority on whether bytes
    |    may be served, and nothing here changes it.
    |  - as a message on the queue configured below, written by the
    |    apps/lambdas/document-scan function and drained by `scan:consume`. That
    |    is what gives the interface a queryable "scanning…" state, and what
    |    carries the key of the thumbnail the same function rendered.
    |
    | Credentials reuse AWS_* like OCR and transcription.
    |
    */

    'results_queue_url' => env('SCAN_RESULTS_QUEUE_URL'),
    'sqs_wait_time_seconds' => (int) env('SCAN_SQS_WAIT_TIME', 20),
    'sqs_max_messages' => (int) env('SCAN_SQS_MAX_MESSAGES', 5),

    /*
    |--------------------------------------------------------------------------
    | Thumbnails
    |--------------------------------------------------------------------------
    |
    | Off by default, because a thumbnail only exists where the document-scan
    | Lambda runs. That is any deployed stack, and locally it is Floci once
    | `composer floci:lambdas` has deployed the function into it — see
    | apps/lambdas/README.md. Everywhere else the interface shows the file-type
    | icon, which is what it falls back to anyway.
    |
    | Turning this on without the Lambda is harmless rather than broken: nothing
    | ever writes a thumbnail path, so every document keeps its icon. It stays
    | off by default so the feature is something you switch on deliberately.
    |
    | Deliberately not checked by ProductionConfigurationValidator: this is a
    | presentation feature, not a security control, and a firm running without
    | previews is missing polish rather than protection.
    |
    */

    'thumbnails' => [
        'enabled' => (bool) env('SCAN_THUMBNAILS_ENABLED', false),
    ],

];
