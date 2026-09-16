<?php

declare(strict_types=1);

namespace App\ClientPortal\Listeners\Outreach;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Notifications\Outreach\ClientPortalReminderNotification;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class LogClientPortalReminderSent
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private TenantContext $tenantContext,
    ) {}

    public function handle(NotificationSent $event): void
    {
        if (
            ! $event->notification instanceof ClientPortalReminderNotification
            || $event->channel !== 'mail'
        ) {
            return;
        }

        try {
            $grant = ClientAccessGrant::queryWithoutTenantScope()
                ->whereKey($event->notification->grantId)
                ->first();

            if (! $grant instanceof ClientAccessGrant) {
                return;
            }

            $tenant = Tenant::query()->whereKey($grant->tenant_id)->first();

            if (! $tenant instanceof Tenant) {
                return;
            }

            $this->tenantContext->runForTenant($tenant, function () use ($grant, $event): void {
                DB::transaction(function () use ($grant, $event): void {
                    // Reminder bookkeeping is per dossier by definition — it
                    // writes next_reminder_at on one. A client-scoped link names
                    // no dossier, and only the per-dossier reminder dispatcher
                    // mints grants that get here, so this is a guard against a
                    // future caller rather than a path that runs today.
                    if ($grant->dossier_id === null) {
                        return;
                    }

                    $dossier = Dossier::query()->whereKey($grant->dossier_id)->firstOrFail();
                    $sentAt = now();

                    $dossier->disableLogging();
                    $dossier->forceFill([
                        'last_client_message_sent_at' => $sentAt,
                        'next_reminder_at' => $dossier->reminder_interval_days === null
                            ? null
                            : $sentAt->copy()->addDays($dossier->reminder_interval_days),
                    ])->save();

                    $this->logAuditActivity->handle(
                        AuditEvent::DossierReminderSent,
                        $dossier,
                        [
                            'grant_id' => $grant->id,
                            'source' => $event->notification->source->value,
                            'channel' => $event->channel,
                            'sent_at' => $sentAt->toIso8601String(),
                        ],
                        includeRequestContext: false,
                    );
                });
            });
        } catch (Throwable $exception) {
            report(new RuntimeException(
                'Failed to record a delivered client reminder.',
                previous: $exception,
            ));
        }
    }
}
