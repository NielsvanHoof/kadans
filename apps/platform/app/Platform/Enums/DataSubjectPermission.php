<?php

declare(strict_types=1);

namespace App\Platform\Enums;

use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Enums\PermissionGroup;

enum DataSubjectPermission: string implements TenantPermission
{
    case View = 'data_subject.view';

    case Manage = 'data_subject.manage';

    public function label(): string
    {
        return match ($this) {
            self::View => __('View data-subject requests'),
            self::Manage => __('Manage data-subject requests'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::View => __('See which clients have asked for a copy of their data or its erasure.'),
            self::Manage => __('Log, fulfil, and refuse those requests. Erasure cannot be undone.'),
        };
    }

    public function group(): PermissionGroup
    {
        return PermissionGroup::DataSubjectRequests;
    }

    /**
     * Empty on purpose: no role carries these by default, so only owners hold
     * them until a firm deliberately grants them. Fulfilling an erasure
     * permanently strips a client's identity, and producing an export puts every
     * document the firm holds about one person into a single downloadable file.
     *
     * Owner is never listed — owners implicitly hold every registered permission.
     */
    public function defaultRoles(): array
    {
        return [];
    }
}
