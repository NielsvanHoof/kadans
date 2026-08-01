<?php

declare(strict_types=1);

namespace App\ClientPortal\Listeners;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Actions\SendClientChangesRequestedNotificationAction;
use App\Dossiers\Events\DocumentRequestRejected;

/**
 * Tell the client which item needs redoing after staff reject it.
 *
 * Synchronous, inside the review transaction — matches the behaviour this
 * replaced, where the review action queued the notification itself.
 */
final readonly class SendClientChangesRequestedOnDocumentRequestRejected
{
    public function __construct(
        private SendClientChangesRequestedNotificationAction $sendClientChangesRequestedNotification,
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    public function handle(DocumentRequestRejected $event): void
    {
        $this->sendClientChangesRequestedNotification->handle($event->documentRequest);

        $this->logAuditActivity->handle(
            AuditEvent::ClientChangesRequestedQueued,
            $event->documentRequest,
            [
                'dossier_id' => $event->documentRequest->dossier_id,
                'channel' => 'mail',
            ],
            $event->reviewer,
        );
    }
}
