<?php

declare(strict_types=1);

namespace App\Platform\Queries;

use App\Platform\Data\DataSubjectRequestData;
use App\Platform\Models\DataSubjectRequest;

/**
 * The workspace compliance list: open requests first, oldest due date first, so
 * whatever is closest to breaching the one-month deadline is at the top.
 */
final class FetchDataSubjectRequestIndexQuery
{
    /**
     * @return list<DataSubjectRequestData>
     */
    public function handle(): array
    {
        $query = DataSubjectRequest::query()->with(['client', 'requestedBy', 'completedBy']);

        $query->getQuery()
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->oldest('due_at');

        $requests = $query->get();

        $rows = [];

        foreach ($requests as $request) {
            $rows[] = DataSubjectRequestData::fromModel($request);
        }

        return $rows;
    }
}
