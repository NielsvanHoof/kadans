<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions\Outreach;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Events\Outreach\ClientQuestionnaireCompleted;
use App\ClientPortal\Notifications\Outreach\ClientQuestionnaireCompletedNotification;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Queries\Lifecycle\DossierHasOutstandingClientItemsQuery;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\Members\FetchActiveUsersForTenantQuery;
use RuntimeException;

/**
 * When every questionnaire item is submitted, notify workspace staff in real time.
 */
final readonly class NotifyWorkspaceIfQuestionnaireCompleteAction
{
    public function __construct(
        private DossierHasOutstandingClientItemsQuery $dossierHasOutstandingClientItems,
        private LogAuditActivityAction $logAuditActivity,
        private FetchActiveUsersForTenantQuery $getActiveUsersForTenant,
    ) {}

    public function handle(Dossier $dossier): void
    {
        // Empty dossiers never count as complete.
        if (! $dossier->documentRequests()->toBase()->exists()) {
            return;
        }

        if ($this->dossierHasOutstandingClientItems->handle($dossier)) {
            return;
        }

        $dossier->disableLogging();
        $dossier->forceFill(['next_reminder_at' => null])->save();

        $dossier->loadMissing(['client', 'tenant']);

        $client = $dossier->client;

        throw_unless($client instanceof Client, RuntimeException::class, 'Dossier client is missing.');

        $tenant = $dossier->tenant;

        throw_unless($tenant instanceof Tenant, RuntimeException::class, 'Dossier tenant is missing.');

        $message = __(':client finished the questionnaire for “:dossier”.', [
            'client' => $client->name,
            'dossier' => $dossier->title,
        ]);

        $dossierUrl = route('workspaces.dossiers.show', [
            'tenant' => $tenant,
            'dossier' => $dossier,
        ]);

        $this->logAuditActivity->handle(
            AuditEvent::ClientQuestionnaireCompleted,
            $dossier,
            [
                'source' => 'client_portal',
                'client_id' => $client->id,
            ],
        );

        // Persist inbox rows before the broadcast so the Echo-triggered reload sees them.
        $notification = new ClientQuestionnaireCompletedNotification(
            tenantId: $tenant->id,
            dossierId: $dossier->id,
            dossierTitle: $dossier->title,
            clientName: $client->name,
            message: $message,
            dossierUrl: $dossierUrl,
        );

        $this->getActiveUsersForTenant->handle($tenant)->each(
            fn (User $user) => $user->notify($notification),
        );

        event(new ClientQuestionnaireCompleted($tenant->slug, $dossier->id, $dossier->title, $client->name, $message, $dossierUrl));
    }
}
