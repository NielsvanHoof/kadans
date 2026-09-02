<?php

declare(strict_types=1);

namespace App\Dossiers\Enums;

use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Enums\PermissionGroup;
use App\Tenancy\Enums\Role;

enum DossierPermission: string implements TenantPermission
{
    case View = 'dossiers.view';
    case Create = 'dossiers.create';
    case Update = 'dossiers.update';
    case Delete = 'dossiers.delete';
    case Restore = 'dossiers.restore';
    case Complete = 'dossiers.complete';
    case Export = 'dossiers.export';
    case SendReminder = 'dossiers.send_reminder';
    case RollOver = 'dossiers.roll_over';

    public function label(): string
    {
        return match ($this) {
            self::View => __('View dossiers'),
            self::Create => __('Create dossiers'),
            self::Update => __('Edit dossiers'),
            self::Delete => __('Archive dossiers'),
            self::Restore => __('Restore archived dossiers'),
            self::Complete => __('Complete dossiers'),
            self::Export => __('Export dossiers'),
            self::SendReminder => __('Send client reminders'),
            self::RollOver => __('Roll dossiers over to a new period'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::View => __('See dossiers and their contents.'),
            self::Create => __('Start new dossiers for a client.'),
            self::Update => __('Change details of an open dossier.'),
            self::Delete => __('Archive dossiers, removing them from the active list.'),
            self::Restore => __('Return archived dossiers to the active list.'),
            self::Complete => __('Mark a dossier as finished, locking further edits.'),
            self::Export => __('Generate a downloadable archive of an entire dossier.'),
            self::SendReminder => __('Email a client asking for outstanding documents.'),
            self::RollOver => __('Start the next period from an existing dossier, keeping its questions and answers.'),
        };
    }

    public function group(): PermissionGroup
    {
        return PermissionGroup::Dossiers;
    }

    public function defaultRoles(): array
    {
        return match ($this) {
            self::View => [Role::Administrator, Role::Adviser, Role::Reviewer, Role::ReadOnly],
            self::Complete => [Role::Administrator, Role::Adviser, Role::Reviewer],
            self::Create,
            self::Update,
            self::Delete,
            self::Restore,
            self::Export,
            self::SendReminder,
            self::RollOver => [Role::Administrator, Role::Adviser],
        };
    }
}
