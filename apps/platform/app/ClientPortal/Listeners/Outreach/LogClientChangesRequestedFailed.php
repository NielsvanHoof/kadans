<?php

declare(strict_types=1);

namespace App\ClientPortal\Listeners\Outreach;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Notifications\Outreach\ClientChangesRequestedNotification;
use App\Dossiers\Models\DocumentRequest;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Notifications\Events\NotificationFailed;
use RuntimeException;
use Throwable;

final readonly class LogClientChangesRequestedFailed
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private TenantContext $tenantContext,
    ) {}

    public function handle(NotificationFailed $event): void
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
                $this->logAuditActivity->handle(
                    AuditEvent::ClientChangesRequestedFailed,
                    $documentRequest,
                    [
                        'dossier_id' => $documentRequest->dossier_id,
                        'channel' => $event->channel,
                    ],
                    includeRequestContext: false,
                );
            });
        } catch (Throwable $exception) {
            report(new RuntimeException(
                'Failed to audit a failed client changes-requested notification.',
                previous: $exception,
            ));
        }
    }
}
