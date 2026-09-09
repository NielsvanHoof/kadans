<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Reminders;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Contracts\Reminders\ClientPortalReminders;
use App\Dossiers\Enums\DossierReminderSource;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Queries\Lifecycle\DossierHasOutstandingClientItemsQuery;
use App\Dossiers\Support\DossierLocks;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SendDossierReminderAction
{
    public function __construct(
        private ClientPortalReminders $clientPortalReminders,
        private DossierHasOutstandingClientItemsQuery $dossierHasOutstandingClientItems,
        private LogAuditActivityAction $logAuditActivity,
        private DossierLocks $dossierLocks,
    ) {}

    public function handle(
        Dossier $dossier,
        User $grantCreator,
        DossierReminderSource $source,
        ?User $auditCauser = null,
        ?string $customMessage = null,
    ): bool {
        return DB::transaction(function () use ($dossier, $grantCreator, $source, $auditCauser, $customMessage): bool {
            $lockedDossier = $this->dossierLocks->dossier($dossier);

            if (
                $source === DossierReminderSource::Scheduled
                && (
                    $lockedDossier->next_reminder_at === null
                    || $lockedDossier->next_reminder_at->isFuture()
                )
            ) {
                return false;
            }

            if ($lockedDossier->status->isLocked()) {
                throw ValidationException::withMessages([
                    'dossier' => __('A completed dossier cannot receive reminders.'),
                ]);
            }

            if (! $this->dossierHasOutstandingClientItems->handle($lockedDossier)) {
                throw ValidationException::withMessages([
                    'dossier' => __('This dossier has no items awaiting the client.'),
                ]);
            }

            $grantId = $this->clientPortalReminders->sendReminder(
                $lockedDossier,
                $grantCreator,
                $source,
                $customMessage,
            );

            $nextReminderAt = $lockedDossier->reminder_interval_days === null
                ? null
                : now()->addDays($lockedDossier->reminder_interval_days);

            $lockedDossier->disableLogging();
            $lockedDossier->forceFill(['next_reminder_at' => $nextReminderAt])->save();

            $this->logAuditActivity->handle(
                AuditEvent::DossierReminderQueued,
                $lockedDossier,
                [
                    'grant_id' => $grantId,
                    'source' => $source->value,
                    'channel' => 'mail',
                    'next_reminder_at' => $nextReminderAt?->toIso8601String(),
                    'custom_message' => $customMessage !== null,
                ],
                $auditCauser,
            );

            return true;
        });
    }
}
