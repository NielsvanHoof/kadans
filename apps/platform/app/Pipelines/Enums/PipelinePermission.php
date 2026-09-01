<?php

declare(strict_types=1);

namespace App\Pipelines\Enums;

use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Enums\PermissionGroup;
use App\Tenancy\Enums\Role;

enum PipelinePermission: string implements TenantPermission
{
    case View = 'pipelines.view';
    case Retry = 'pipelines.retry';
    case Cancel = 'pipelines.cancel';
    case Delete = 'pipelines.delete';

    public function label(): string
    {
        return match ($this) {
            self::View => __('View processing runs'),
            self::Retry => __('Retry processing runs'),
            self::Cancel => __('Cancel processing runs'),
            self::Delete => __('Delete processing runs'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::View => __('View document processing and export history, including errors.'),
            self::Retry => __('Re-run a failed or stuck OCR, transcription, or export run.'),
            self::Cancel => __('Stop a pending or running OCR, transcription, or export run.'),
            self::Delete => __('Delete a completed processing job and its stored data.'),
        };
    }

    public function group(): PermissionGroup
    {
        return PermissionGroup::Processing;
    }

    public function defaultRoles(): array
    {
        return match ($this) {
            self::View => [Role::Administrator],
            self::Retry, self::Cancel, self::Delete => [Role::Administrator, Role::Adviser],
        };
    }
}
