<?php

declare(strict_types=1);

namespace App\Dossiers\Enums;

use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Enums\PermissionGroup;
use App\Tenancy\Enums\Role;

enum DocumentRequestPermission: string implements TenantPermission
{
    case View = 'document_requests.view';
    case Create = 'document_requests.create';
    case Update = 'document_requests.update';
    case Delete = 'document_requests.delete';
    case Upload = 'document_requests.upload';
    case Review = 'document_requests.review';

    public function label(): string
    {
        return match ($this) {
            self::View => __('View document requests'),
            self::Create => __('Add document requests'),
            self::Update => __('Edit document requests'),
            self::Delete => __('Remove document requests'),
            self::Upload => __('Upload on behalf of a client'),
            self::Review => __('Review submitted documents'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::View => __('See which documents have been requested from a client.'),
            self::Create => __('Ask a client for an additional document.'),
            self::Update => __('Change the wording or settings of a request.'),
            self::Delete => __('Withdraw a document request.'),
            self::Upload => __('Attach files received outside the portal, such as by email.'),
            self::Review => __('Accept or reject documents a client submitted.'),
        };
    }

    public function group(): PermissionGroup
    {
        return PermissionGroup::DocumentRequests;
    }

    public function defaultRoles(): array
    {
        return match ($this) {
            self::View => [Role::Administrator, Role::Adviser, Role::Reviewer, Role::ReadOnly],
            self::Review => [Role::Administrator, Role::Adviser, Role::Reviewer],
            self::Create,
            self::Update,
            self::Delete,
            self::Upload => [Role::Administrator, Role::Adviser],
        };
    }
}
