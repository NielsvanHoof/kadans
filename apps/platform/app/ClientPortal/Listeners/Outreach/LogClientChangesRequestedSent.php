<?php

declare(strict_types=1);

namespace App\ClientPortal\Listeners\Outreach;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Notifications\Outreach\ClientChangesRequestedNotification;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class LogClientChangesRequestedSent
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private TenantContext $tenantContext,
    ) {}

    public function handle(NotificationSent $event): void
    {
        if (
            ! $event->notification instanceof ClientChangesRequestedNotification
            || $event->channel !== 'mail'
        ) {
            return;
        }

        try {
            $documentRequest = DocumentRequest::queryWithoutTenantScope()
                ->whereKey($event->notification->documentRequestId)
                ->first();

            if (! $documentRequest instanceof DocumentRequest) {
                return;
            }

            $tenant = Tenant::query()->whereKey($documentRequest->tenant_id)->first();

            if (! $tenant instanceof Tenant) {
                return;
            }

            $this->tenantContext->runForTenant($tenant, function () use ($documentRequest, $event): void {
                DB::transaction(function () use ($documentRequest, $event): void {
                    $dossier = Dossier::query()->whereKey($documentRequest->dossier_id)->firstOrFail();
                    $sentAt = now();

                    $dossier->disableLogging();
                    $dossier->forceFill([
                        'last_client_message_sent_at' => $sentAt,
                        'next_reminder_at' => $dossier->reminder_interval_days === null
                            ? null
                            : $sentAt->copy()->addDays($dossier->reminder_interval_days),
                    ])->save();

                    $this->logAuditActivity->handle(
                        AuditEvent::ClientChangesRequestedSent,
                        $documentRequest,
                        [
                            'dossier_id' => $documentRequest->dossier_id,
                            'channel' => $event->channel,
                            'sent_at' => $sentAt->toIso8601String(),
                        ],
                        includeRequestContext: false,
                    );
                });
            });
        } catch (Throwable $exception) {
            report(new RuntimeException(
                'Failed to audit a delivered client changes-requested notification.',
                previous: $exception,
            ));
        }
    }
}
