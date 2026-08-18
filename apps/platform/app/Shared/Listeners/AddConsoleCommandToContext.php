<?php

declare(strict_types=1);

namespace App\Shared\Listeners;

use App\Shared\Observability\ObservabilityContext;
use Illuminate\Console\Events\CommandStarting;

/**
 * Give console runs the same correlation id and name that requests get.
 *
 * Scheduled work — the retention purge, pipeline retries, the SQS consumers —
 * writes most of its logs outside any request, so without this those records
 * carry no way to group one run's output together.
 */
final readonly class AddConsoleCommandToContext
{
    public function handle(CommandStarting $event): void
    {
        ObservabilityContext::rememberRequestId(null);
        ObservabilityContext::rememberCommand($event->command);
    }
}
