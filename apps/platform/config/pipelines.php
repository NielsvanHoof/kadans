<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic retry backoff
    |--------------------------------------------------------------------------
    |
    | A failed run schedules itself for another attempt on an exponential
    | backoff: base_delay_minutes * (multiplier ^ (attempt_number - 1)), capped
    | at max_delay_minutes. pipelines:retry-due picks up whatever is due.
    |
    | max_attempts is the point at which the platform stops trying. A run that
    | has burned that many attempts is failing for a reason a retry will not
    | fix, so it stays failed with next_retry_at null and waits for a human,
    | who can still press Retry.
    |
    */

    'retry' => [
        'max_attempts' => (int) env('PIPELINE_RETRY_MAX_ATTEMPTS', 3),
        'base_delay_minutes' => (int) env('PIPELINE_RETRY_BASE_DELAY_MINUTES', 5),
        'multiplier' => (int) env('PIPELINE_RETRY_MULTIPLIER', 4),
        'max_delay_minutes' => (int) env('PIPELINE_RETRY_MAX_DELAY_MINUTES', 240),
        'batch_size' => (int) env('PIPELINE_RETRY_BATCH_SIZE', 50),
    ],

];
