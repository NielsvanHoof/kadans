<?php

declare(strict_types=1);

namespace App\ClientPortal\Support;

use App\Accounts\Models\User;
use App\ClientPortal\Actions\CreateClientAccessGrantAction;
use App\ClientPortal\Notifications\ClientPortalReminderNotification;
use App\Clients\Models\Client;
use App\Dossiers\Contracts\ClientPortalReminders;
use App\Dossiers\Enums\DossierReminderSource;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * ClientPortal's implementation of the Dossiers-facing reminder contract.
 *
 * Owns everything portal-shaped about a reminder: the grant, the magic link,
 * and the client notification. The caller keeps the scheduling decisions.
 */
final readonly class ClientPortalReminderDispatcher implements ClientPortalReminders
{
    /** Portal links issued for reminders stay valid for this many days. */
    private const int GRANT_TTL_DAYS = 7;

    public function __construct(
        private CreateClientAccessGrantAction $createClientAccessGrant,
    ) {}

    public function sendReminder(
        Dossier $dossier,
        User $grantCreator,
        DossierReminderSource $source,
        ?string $customMessage = null,
    ): int {
        $dossier->loadMissing(['client', 'tenant']);

        $client = $dossier->client;

        throw_unless($client instanceof Client, RuntimeException::class, 'Dossier client is missing.');

        $result = $this->createClientAccessGrant->handle(
            $dossier,
            $grantCreator,
            self::GRANT_TTL_DAYS,
        );

        $firmName = $dossier->tenant instanceof Tenant
            ? $dossier->tenant->name
            : Config::string('app.name');

        $portalUrl = URL::route('portal.exchange', [
            'token' => $result['plain_text_token'],
        ]);

        Notification::route('mail', $client->email)
            ->notify(
                new ClientPortalReminderNotification(
                    grantId: $result['grant']->id,
                    dossier: $dossier,
                    portalUrl: $portalUrl,
                    expiresAt: $result['grant']->expires_at,
                    firmName: $firmName,
                    source: $source,
                    customMessage: $customMessage,
                )->locale($grantCreator->preferredLocale()),
            );

        return $result['grant']->id;
    }
}
