<?php

declare(strict_types=1);

namespace App\Platform\Actions\Retention;

use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use Illuminate\Support\Facades\DB;

final readonly class PurgeExpiredClientAction
{
    public function __construct(
        private PurgeSubjectActivityLogAction $purgeSubjectActivityLog,
    ) {}

    /**
     * @return array{purged: bool, activity_rows_deleted: int}
     */
    public function handle(Client $client): array
    {
        if (! $client->trashed()) {
            return [
                'purged' => false,
                'activity_rows_deleted' => 0,
            ];
        }

        if (Dossier::withTrashed()->whereBelongsTo($client)->toBase()->exists()) {
            return [
                'purged' => false,
                'activity_rows_deleted' => 0,
            ];
        }

        return DB::transaction(function () use ($client): array {
            $activityRowsDeleted = $this->purgeSubjectActivityLog->handle($client);

            $client->disableLogging();
            $client->forceDelete();

            return [
                'purged' => true,
                'activity_rows_deleted' => $activityRowsDeleted,
            ];
        });
    }
}
