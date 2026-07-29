<?php

declare(strict_types=1);

namespace App\Questionnaires\Enums;

use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Enums\PermissionGroup;
use App\Tenancy\Enums\Role;

enum QuestionnaireTemplatePermission: string implements TenantPermission
{
    case View = 'templates.view';
    case Create = 'templates.create';
    case Update = 'templates.update';
    case Delete = 'templates.delete';
    case Copy = 'templates.copy';
    case Apply = 'templates.apply';

    public function label(): string
    {
        return match ($this) {
            self::View => __('View templates'),
            self::Create => __('Create templates'),
            self::Update => __('Edit templates'),
            self::Delete => __('Delete templates'),
            self::Copy => __('Copy templates'),
            self::Apply => __('Apply templates to dossiers'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::View => __('Browse the questionnaire templates of the firm.'),
            self::Create => __('Build a new questionnaire template.'),
            self::Update => __('Change the questions in a firm template.'),
            self::Delete => __('Remove a firm template.'),
            self::Copy => __('Duplicate a system or firm template into the firm.'),
            self::Apply => __('Use a template to populate the questionnaire of a dossier.'),
        };
    }

    public function group(): PermissionGroup
    {
        return PermissionGroup::Templates;
    }

    public function defaultRoles(): array
    {
        return match ($this) {
            self::View => [Role::Administrator, Role::Adviser, Role::Reviewer, Role::ReadOnly],
            self::Apply => [Role::Administrator, Role::Adviser],
            self::Create,
            self::Update,
            self::Delete,
            self::Copy => [Role::Administrator],
        };
    }
}
