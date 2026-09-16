<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions\Access;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Actions\Outreach\SendClientPortalInviteAction;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Models\Dossier;
use Illuminate\Support\Facades\DB;

final readonly class IssueClientPortalAccessAction
{
    public function __construct(
        private CreateClientAccessGrantAction $createClientAccessGrant,
        private SendClientPortalInviteAction $sendClientPortalInvite,
        private LogAuditActivityAction $logAuditActivity,
    ) {}

    /**
     * @return array{grant: ClientAccessGrant, plain_text_token: string}
     */
    public function handle(
        Dossier $dossier,
        User $createdBy,
        int $expiresInDays,
        bool $sendInvite,
    ): array {
        return DB::transaction(function () use (
            $dossier,
            $createdBy,
            $expiresInDays,
            $sendInvite,
        ): array {
            $result = $this->createClientAccessGrant->handle(
                $dossier,
                $createdBy,
                $expiresInDays,
            );

            $this->logAuditActivity->handle(
                AuditEvent::ClientPortalAccessGrantCreated,
                $result['grant'],
                [
                    'client_id' => $dossier->client_id,
                    'dossier_id' => $dossier->id,
                    'expires_at' => $result['grant']->expires_at->toIso8601String(),
                    'invite_requested' => $sendInvite,
                ],
                $createdBy,
            );

            if ($sendInvite) {
                $this->sendClientPortalInvite->handle(
                    $dossier,
                    $result['grant'],
                    $result['plain_text_token'],
                );

                $this->logAuditActivity->handle(
                    AuditEvent::ClientPortalInviteQueued,
                    $result['grant'],
                    [
                        'dossier_id' => $dossier->id,
                        'channel' => 'mail',
                    ],
                    $createdBy,
                );

                if ($dossier->reminder_interval_days !== null) {
                    $dossier->disableLogging();
                    $dossier->forceFill([
                        'next_reminder_at' => now()->addDays($dossier->reminder_interval_days),
                    ])->save();
                }
            }

            return $result;
        });
    }
}
