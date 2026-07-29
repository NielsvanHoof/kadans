<?php

declare(strict_types=1);

namespace App\Dossiers\Actions;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Actions\CreateClientAccessGrantAction;
use App\ClientPortal\Notifications\ClientPortalReminderNotification;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierReminderSource;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Queries\DossierHasOutstandingClientItemsQuery;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final readonly class SendDossierReminderAction
{
    public function __construct(
        private CreateClientAccessGrantAction $createClientAccessGrant,
        private DossierHasOutstandingClientItemsQuery $dossierHasOutstandingClientItems,
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    public function handle(
        Dossier $dossier,
        User $grantCreator,
        DossierReminderSource $source,
        ?User $auditCauser = null,
    ): bool {
        return DB::transaction(function () use ($dossier, $grantCreator, $source, $auditCauser): bool {
            $dossierQuery = Dossier::query()->whereKey($dossier->getKey());
            $dossierQuery->getQuery()->lockForUpdate();
            $lockedDossier = $dossierQuery->firstOrFail();

            if (
                $source === DossierReminderSource::Scheduled
                && (
                    $lockedDossier->next_reminder_at === null
                    || $lockedDossier->next_reminder_at->isFuture()
                )
            ) {
                return false;
            }

            if ($lockedDossier->status === DossierStatus::Completed) {
                throw ValidationException::withMessages([
                    'dossier' => __('A completed dossier cannot receive reminders.'),
                ]);
            }

            if (! $this->dossierHasOutstandingClientItems->handle($lockedDossier)) {
                throw ValidationException::withMessages([
                    'dossier' => __('This dossier has no items awaiting the client.'),
                ]);
            }

            $lockedDossier->loadMissing(['client', 'tenant']);
            $client = $lockedDossier->client;

            throw_unless($client instanceof Client, RuntimeException::class, 'Dossier client is missing.');

            $result = $this->createClientAccessGrant->handle(
                $lockedDossier,
                $grantCreator,
                7,
            );

            $firmName = $lockedDossier->tenant instanceof Tenant
                ? $lockedDossier->tenant->name
                : (string) config('app.name');
            $portalUrl = URL::route('portal.exchange', [
                'token' => $result['plain_text_token'],
            ]);

            $locale = $grantCreator->preferredLocale();

            Notification::route('mail', $client->email)
                ->notify(
                    new ClientPortalReminderNotification(
                        grantId: $result['grant']->id,
                        dossier: $lockedDossier,
                        portalUrl: $portalUrl,
                        expiresAt: $result['grant']->expires_at,
                        firmName: $firmName,
                        source: $source,
                    )->locale($locale),
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
                    'grant_id' => $result['grant']->id,
                    'source' => $source->value,
                    'channel' => 'mail',
                    'next_reminder_at' => $nextReminderAt?->toIso8601String(),
                ],
                $auditCauser,
            );

            return true;
        });
    }
}
