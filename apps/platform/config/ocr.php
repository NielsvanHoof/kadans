<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | OCR Driver
    |--------------------------------------------------------------------------
    |
    | mock     — sync fake text (CI / offline). Completes inside the upload job.
    | demo     — like mock, but realistic Dutch extractions and a short pause so
    |            the pipeline progress is visible during a live walkthrough.
    | textract — async AWS Textract StartDocumentAnalysis (FORMS + TABLES) via
    |            SNS/SQS, then DocumentOverviewAgent fills document_type /
    |            summary / notes. That agent's provider and model are
    |            configured in config/ai.php under agents.document_overview.
    |
    */

    'driver' => env('OCR_DRIVER', 'mock'),

    'demo' => [
        // Seconds the demo driver waits before completing. 0 disables the pause.
        'delay_seconds' => (int) env('OCR_DEMO_DELAY_SECONDS', 3),
    ],

    'textract' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        // Set when using temporary credentials from sts:AssumeRole (ocr operator).
        'token' => env('AWS_SESSION_TOKEN'),
        'region' => env('AWS_DEFAULT_REGION', 'eu-west-1'),
        // Local Floci/Sail only. Leave empty in production so the SDK uses real AWS.
        'endpoint' => env('AWS_ENDPOINT'),
        'bucket' => env('AWS_BUCKET'),
        // SNS topic Textract publishes completion events to.
        'sns_topic_arn' => env('TEXTRACT_SNS_TOPIC_ARN'),
        // IAM role Textract assumes to publish to SNS.
        'sns_role_arn' => env('TEXTRACT_SNS_ROLE_ARN'),
        // SQS queue subscribed to the SNS topic (SNS envelope, not Laravel jobs).
        'results_queue_url' => env('TEXTRACT_RESULTS_QUEUE_URL'),
        // Long-poll wait for textract:consume (seconds, max 20).
        'sqs_wait_time_seconds' => (int) env('TEXTRACT_SQS_WAIT_TIME', 20),
        'sqs_max_messages' => (int) env('TEXTRACT_SQS_MAX_MESSAGES', 5),
        /*
         * Mirrors the queue's redrive policy (infra/src/shared/documents.ts).
         *
         * The consumer no longer counts receives to decide when to give up —
         * that is now split properly: SQS's own redrive moves a message it
         * cannot parse to the DLQ, and ApplyTextractResultJob's attempt budget
         * owns processing failures. The value is kept and validated because it
         * documents the deployed policy, and a mismatch between this and the
         * queue is worth catching at boot.
         */
        'sqs_max_receive_count' => (int) env('TEXTRACT_SQS_MAX_RECEIVE_COUNT', 5),
    ],

];
