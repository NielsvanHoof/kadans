<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions;

use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Notifications\ClientPortalInviteNotification;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use RuntimeException;

final class SendClientPortalInviteAction
{
    /**
     * Email the dossier's client a magic link that opens the portal with this grant.
     */
    public function handle(
        Dossier $dossier,
        ClientAccessGrant $grant,
        string $plainTextToken,
    ): void {
        $dossier->loadMissing(['client', 'tenant']);

        $client = $dossier->client;

        if (! $client instanceof Client) {
            throw new RuntimeException('Dossier client is missing.');
        }

        $firmName = $dossier->tenant instanceof Tenant
            ? $dossier->tenant->name
            : (string) config('app.name');

        // Absolute portal URL embedding the one-time plain token.
        $portalUrl = URL::route('portal.exchange', ['token' => $plainTextToken]);

        Notification::route('mail', $client->email)
            ->notify(
                (new ClientPortalInviteNotification(
                    grantId: $grant->id,
                    dossier: $dossier,
                    portalUrl: $portalUrl,
                    expiresAt: $grant->expires_at,
                    firmName: $firmName,
                ))->locale(app()->getLocale()),
            );
    }
}
