<?php

declare(strict_types=1);

namespace App\ClientPortal\Listeners;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Notifications\ClientPortalInviteNotification;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Notifications\Events\NotificationFailed;
use RuntimeException;
use Throwable;

final readonly class LogClientPortalInviteFailed
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private TenantContext $tenantContext,
    ) {}

    public function handle(NotificationFailed $event): void
    {
        if (
            ! $event->notification instanceof ClientPortalInviteNotification
            || $event->channel !== 'mail'
        ) {
            return;
        }

        try {
            $grant = ClientAccessGrant::query()
                ->withoutGlobalScopes()
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
                $this->logAuditActivity->handle(
                    AuditEvent::ClientPortalInviteFailed,
                    $grant,
                    [
                        'dossier_id' => $grant->dossier_id,
                        'channel' => $event->channel,
                    ],
                    includeRequestContext: false,
                );
            });
        } catch (Throwable $exception) {
            report(new RuntimeException(
                'Failed to audit a failed client portal invitation.',
                previous: $exception,
            ));
        }
    }
}
