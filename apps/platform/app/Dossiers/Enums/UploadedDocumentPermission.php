<?php

declare(strict_types=1);

namespace App\Dossiers\Enums;

use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Enums\PermissionGroup;
use App\Tenancy\Enums\Role;

enum UploadedDocumentPermission: string implements TenantPermission
{
    case View = 'documents.view';
    case Download = 'documents.download';

    public function label(): string
    {
        return match ($this) {
            self::View => __('View documents'),
            self::Download => __('Download documents'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::View => __('Open a document and its extracted data in the browser.'),
            self::Download => __('Save the original file to a local device.'),
        };
    }

    public function group(): PermissionGroup
    {
        return PermissionGroup::Documents;
    }

    public function defaultRoles(): array
    {
        return match ($this) {
            self::View => [Role::Administrator, Role::Adviser, Role::Reviewer, Role::ReadOnly],
            self::Download => [Role::Administrator, Role::Adviser],
        };
    }
}
