<?php

declare(strict_types=1);

namespace App\Platform\Policies;

use App\Accounts\Models\User;
use App\Platform\Enums\DataSubjectPermission;
use App\Platform\Models\DataSubjectRequest;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class DataSubjectRequestPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, DataSubjectPermission::View);
    }

    public function create(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, DataSubjectPermission::Manage);
    }

    /**
     * Fulfilling produces a complete copy of one person, or permanently strips
     * their identity — the same gate either way.
     */
    public function fulfil(User $user, DataSubjectRequest $request): bool
    {
        return $request->status->isOpen()
            && $this->userHasPermissionInCurrentTenant($user, DataSubjectPermission::Manage);
    }

    public function refuse(User $user, DataSubjectRequest $request): bool
    {
        return $request->status->isOpen()
            && $this->userHasPermissionInCurrentTenant($user, DataSubjectPermission::Manage);
    }

    /**
     * The archive holds every document the firm has about one person, so the
     * download is gated on Manage rather than View — and the route additionally
     * requires a recent password confirmation, like every document route.
     */
    public function download(User $user, DataSubjectRequest $request): bool
    {
        return $request->isDownloadable()
            && $this->userHasPermissionInCurrentTenant($user, DataSubjectPermission::Manage);
    }
}
