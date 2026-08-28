<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Support\WorkspaceChannelAccess;
use Illuminate\Support\Facades\Broadcast;

/*
 * Private workspace channels — active members of the tenant may subscribe.
 * Echo channel name: private-workspaces.{slug}
 */
Broadcast::channel('workspaces.{tenantSlug}', fn (User $user, string $tenantSlug): bool => WorkspaceChannelAccess::memberTenant($user, $tenantSlug) instanceof Tenant);

/*
 * Firm-wide pipeline stream — pipeline admin pages, and the document/recording
 * pages whose dossier is not always known.
 *
 * Same membership rule as the workspace channel: it carries no more than the
 * per-dossier stream does, only more of it.
 */
Broadcast::channel('workspaces.{tenantSlug}.pipelines', fn (User $user, string $tenantSlug): bool => WorkspaceChannelAccess::memberTenant($user, $tenantSlug) instanceof Tenant);

/*
 * Per-dossier pipeline stream. Membership is not enough on its own here: the
 * dossier id comes from the subscriber, so it has to be confirmed to belong to
 * the firm they are a member of, or a member of firm A could name firm B's
 * dossier and receive its run traffic.
 */
Broadcast::channel('workspaces.{tenantSlug}.dossiers.{dossierId}', function (User $user, string $tenantSlug, string $dossierId): bool {
    $tenant = WorkspaceChannelAccess::memberTenant($user, $tenantSlug);

    if (! $tenant instanceof Tenant) {
        return false;
    }

    if (! ctype_digit($dossierId)) {
        return false;
    }

    // queryWithoutTenantScope + an explicit tenant_id: broadcast auth runs
    // outside a tenant context, so the ownership check has to be spelled out
    // rather than assumed. toBase() keeps the soft-delete scope, so an archived
    // dossier stops streaming.
    return Dossier::queryWithoutTenantScope()
        ->whereKey((int) $dossierId)
        ->where('tenant_id', $tenant->getKey())
        ->toBase()
        ->exists();
});
