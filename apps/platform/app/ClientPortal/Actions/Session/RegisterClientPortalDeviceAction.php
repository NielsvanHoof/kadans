<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions\Session;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Enums\NewPortalDeviceAction;
use App\ClientPortal\Enums\PortalDeviceOutcome;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Notifications\Session\ClientPortalNewDeviceNotification;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\Members\FetchActiveUsersForTenantQuery;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

use function hash_equals;
use function is_string;

/**
 * Compare a magic-link redemption against the device that redeemed it first.
 *
 * The grant token stays re-exchangeable until it expires — that tradeoff is
 * documented and deliberate. This closes the part that was actually missing:
 * nobody was told when a link started being used from somewhere else.
 */
final readonly class RegisterClientPortalDeviceAction
{
    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private FetchActiveUsersForTenantQuery $getActiveUsersForTenant,
        private Repository $config,
    ) {}

    public function handle(ClientAccessGrant $grant, string $fingerprint): PortalDeviceOutcome
    {
        $action = $this->configuredAction();

        if ($action === NewPortalDeviceAction::Ignore) {
            return PortalDeviceOutcome::Recognised;
        }

        $known = $grant->device_fingerprint;

        if (! is_string($known) || $known === '') {
            $grant->forceFill(['device_fingerprint' => $fingerprint])->save();

            return PortalDeviceOutcome::FirstUse;
        }

        if (hash_equals($known, $fingerprint)) {
            return PortalDeviceOutcome::Recognised;
        }

        if ($action === NewPortalDeviceAction::Block) {
            $this->logAuditActivity->handle(
                AuditEvent::ClientPortalNewDeviceBlocked,
                $grant,
                ['client_id' => $grant->client_id, 'dossier_id' => $grant->dossier_id],
            );

            return PortalDeviceOutcome::NewDeviceBlocked;
        }

        // At most one alert per grant. The signal worth acting on is "this link
        // is being opened from more than one place", and repeating it every time
        // a client switches between phone and laptop would bury it.
        if ($grant->new_device_seen_at === null) {
            $this->raiseAlert($grant);
        }

        return PortalDeviceOutcome::NewDeviceAllowed;
    }

    /**
     * An unrecognised value falls back to notifying rather than ignoring: a typo
     * in the deploy environment must not silently switch the signal off.
     */
    private function configuredAction(): NewPortalDeviceAction
    {
        $configured = $this->config->get('portal.new_device_action');

        return NewPortalDeviceAction::tryFrom(is_string($configured) ? $configured : '') // @pest-mutate-ignore empty fallback is defensive config typing
            ?? NewPortalDeviceAction::Notify;
    }

    /**
     * A client-scoped link has no dossier to name, so the alert is built around
     * the client and points at their page. Falling through to "no dossier, no
     * alert" would have quietly switched this signal off for exactly the scope
     * that opens the most — see decision-4, where new-device watching is one of
     * the compensating controls for re-exchangeable tokens.
     */
    private function raiseAlert(ClientAccessGrant $grant): void
    {
        $grant->loadMissing(['client', 'tenant']); // @pest-mutate-ignore relations lazy-load without this; call keeps them explicit

        $dossier = $grant->dossier_id === null
            ? null
            : Dossier::query()->whereKey($grant->dossier_id)->first();

        $client = $dossier instanceof Dossier ? $dossier->client : $grant->client;
        $tenant = $dossier instanceof Dossier ? $dossier->tenant : $grant->tenant;

        if (! $client instanceof Client || ! $tenant instanceof Tenant) { // @pest-mutate-ignore orphan relations are schema failures, not product paths
            return;
        }

        $notification = new ClientPortalNewDeviceNotification(
            tenantId: $tenant->id,
            dossierId: $dossier?->id,
            dossierTitle: $dossier?->title,
            clientName: $client->name,
            dossierUrl: $dossier instanceof Dossier
                ? route('workspaces.dossiers.show', ['tenant' => $tenant, 'dossier' => $dossier])
                : route('workspaces.clients.show', ['tenant' => $tenant, 'client' => $client]),
        );

        DB::transaction(function () use ($grant, $dossier, $tenant, $notification): void {
            $grant->forceFill(['new_device_seen_at' => now()])->save();

            $this->logAuditActivity->handle(
                AuditEvent::ClientPortalAccessedFromNewDevice,
                $grant,
                [
                    'client_id' => $grant->client_id,
                    'dossier_id' => $grant->dossier_id,
                    // The hash itself stays out of the trail; that it changed is
                    // the whole finding.
                    'fingerprint_changed' => true,
                ],
            );

            $this->recipients($dossier, $tenant)->each(
                fn (User $user) => $user->notify($notification),
            );
        });
    }

    /**
     * The responsible staff member, or every active member when there is none —
     * a security signal must not be delivered to nobody. A client-level link
     * has no single responsible member, so it always goes to everyone.
     *
     * @return Collection<int, User>
     */
    private function recipients(?Dossier $dossier, Tenant $tenant): Collection
    {
        $responsible = $dossier?->responsibleUser;

        if ($responsible instanceof User) {
            /** @var Collection<int, User> $only */
            $only = new Collection([$responsible]);

            return $only;
        }

        return $this->getActiveUsersForTenant->handle($tenant);
    }
}
