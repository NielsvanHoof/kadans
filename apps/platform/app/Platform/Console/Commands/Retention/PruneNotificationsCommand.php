<?php

declare(strict_types=1);

namespace App\Platform\Console\Commands\Retention;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\info;
use function sprintf;

#[Signature('retention:prune-notifications')]
#[Description('Delete database notifications older than the configured retention period')]
final class PruneNotificationsCommand extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('retention.notifications_days'));

        $deleted = DB::table('notifications')
            ->where('created_at', '<=', $cutoff)
            ->delete();

        info(sprintf('Deleted %d notification(s) created on or before %s.', $deleted, $cutoff->toDateString()));

        return self::SUCCESS;
    }
}
