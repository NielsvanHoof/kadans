<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

use App\Dossiers\Enums\DossierStatus;
use App\Shared\Data\PersonOptionData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Dossier detail object on the overview (show) page.
 */
#[TypeScript]
final class DossierShowData extends Data
{
    /**
     * @param  list<StaffDocumentRequestData>  $documentRequests
     * @param  list<DossierAccessGrantData>  $accessGrants
     * @param  list<DossierExportData>  $exports
     * @param  list<StaffAudioRecordingData>  $audioRecordings
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $reference,
        public readonly DossierStatus $status,
        public readonly ?string $dueDate,
        public readonly ?PersonOptionData $responsibleStaff,
        public readonly ?int $reminderIntervalDays,
        public readonly ?string $nextReminderAt,
        public readonly ?string $lastClientMessageSentAt,
        public readonly ?string $lastClientOpenedAt,
        public readonly bool $hasOutstandingClientItems,
        public readonly bool $readyToComplete,
        public readonly DossierReviewSummaryData $reviewSummary,
        public readonly DossierClientSummaryData $client,
        public readonly array $documentRequests,
        public readonly array $accessGrants,
        public readonly array $exports,
        public readonly array $audioRecordings,
    ) {}
}
