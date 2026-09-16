<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions\Outreach;

use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Notifications\Outreach\ClientPortalLinkInviteNotification;
use App\ClientPortal\Queries\Access\FetchReachableDossiersQuery;
use App\Clients\Models\Client;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

final readonly class SendClientPortalLinkInviteAction
{
    public function __construct(private FetchReachableDossiersQuery $fetchReachableDossiers) {}

    /**
     * Email the client a magic link that opens everything shared with them.
     */
    public function handle(Client $client, ClientAccessGrant $grant, string $plainTextToken): void
    {
        $client->loadMissing('tenant');

        $firmName = $client->tenant instanceof Tenant
            ? $client->tenant->name
            : Config::string('app.name');

        // Absolute portal URL embedding the one-time plain token.
        $portalUrl = URL::route('portal.exchange', ['token' => $plainTextToken]);

        Notification::route('mail', $client->email)
            ->notify(
                new ClientPortalLinkInviteNotification(
                    grantId: $grant->id,
                    clientName: $client->name,
                    portalUrl: $portalUrl,
                    expiresAt: $grant->expires_at,
                    firmName: $firmName,
                    waitingCount: $this->fetchReachableDossiers->handle($grant)->count(),
                )->locale(app()->getLocale()),
            );
    }
}
