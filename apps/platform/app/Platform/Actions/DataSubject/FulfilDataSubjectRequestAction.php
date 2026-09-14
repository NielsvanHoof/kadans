<?php

declare(strict_types=1);

namespace App\Platform\Actions\DataSubject;

use App\Accounts\Models\User;
use App\Platform\Enums\DataSubjectRequestType;
use App\Platform\Jobs\DataSubject\AnonymizeDataSubjectJob;
use App\Platform\Jobs\DataSubject\GenerateDataSubjectExportJob;
use App\Platform\Models\DataSubjectRequest;

/**
 * Queue export or erasure work for a logged data-subject request.
 *
 * Controllers authorize and toast; this action owns the after-commit dispatch
 * so fulfil cannot drift from other mutation paths.
 */
final readonly class FulfilDataSubjectRequestAction
{
    public function handle(DataSubjectRequest $dataSubjectRequest, User $fulfilledBy): DataSubjectRequestType
    {
        if ($dataSubjectRequest->type === DataSubjectRequestType::Export) {
            dispatch(new GenerateDataSubjectExportJob($dataSubjectRequest->id))->afterCommit();

            return DataSubjectRequestType::Export;
        }

        dispatch(new AnonymizeDataSubjectJob(
            $dataSubjectRequest->id,
            $fulfilledBy->id,
        ))->afterCommit();

        return DataSubjectRequestType::Erasure;
    }
}
