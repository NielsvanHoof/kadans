<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions\Access;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Actions\Outreach\SendClientPortalLinkInviteAction;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Give a client one standing link to everything their firm shares with them.
 *
 * Shares nothing by itself. That is the difference from the per-dossier
 * equivalent, which publishes the dossier it mints a token for: a standing link
 * is a door, and which dossiers sit behind it is decided one at a time with
 * ShareDossierWithClientAction.
 */
final readonly class IssueClientPortalLinkAction
{
    public function __construct(
        private CreateClientAccessGrantAction $createClientAccessGrant,
        private SendClientPortalLinkInviteAction $sendClientPortalLinkInvite,
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    /**
     * @return array{grant: ClientAccessGrant, plain_text_token: string}
     */
    public function handle(
        Client $client,
        User $createdBy,
        ?int $expiresInDays = null,
        bool $sendInvite = true,
    ): array {
        $expiresInDays ??= Config::integer('portal.client_grant_ttl_days');

        $result = DB::transaction(fn (): array => $this->createClientAccessGrant->handleForClient(
            $client,
            $createdBy,
            $expiresInDays,
        ));

        $this->logAuditActivity->handle(
            AuditEvent::ClientPortalAccessGrantCreated,
            $result['grant'],
            [
                'scope' => $result['grant']->scope->value,
                'client_id' => $client->getKey(),
                'expires_at' => $result['grant']->expires_at->toIso8601String(),
            ],
            $createdBy,
        );

        if ($sendInvite) {
            $this->sendClientPortalLinkInvite->handle($client, $result['grant'], $result['plain_text_token']);

            $this->logAuditActivity->handle(
                AuditEvent::ClientPortalInviteQueued,
                $result['grant'],
                [
                    'scope' => $result['grant']->scope->value,
                    'client_id' => $client->getKey(),
                ],
                $createdBy,
            );
        }

        return $result;
    }
}
