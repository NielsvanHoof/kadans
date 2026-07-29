<?php

declare(strict_types=1);

namespace App\Questionnaires\Policies;

use App\Accounts\Models\User;
use App\Questionnaires\Enums\QuestionnaireTemplatePermission;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class QuestionnaireTemplatePolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, QuestionnaireTemplatePermission::View);
    }

    public function view(User $user, QuestionnaireTemplate $questionnaireTemplate): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, QuestionnaireTemplatePermission::View)
            && $this->isVisibleToUser($user, $questionnaireTemplate);
    }

    public function create(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, QuestionnaireTemplatePermission::Create);
    }

    public function update(User $user, QuestionnaireTemplate $questionnaireTemplate): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, QuestionnaireTemplatePermission::Update)
            && $this->isEditableFirmTemplate($user, $questionnaireTemplate);
    }

    public function delete(User $user, QuestionnaireTemplate $questionnaireTemplate): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, QuestionnaireTemplatePermission::Delete)
            && $this->isEditableFirmTemplate($user, $questionnaireTemplate);
    }

    /**
     * Copy a system or firm template into the current firm.
     */
    public function copy(User $user, QuestionnaireTemplate $questionnaireTemplate): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, QuestionnaireTemplatePermission::Copy)
            && $this->isVisibleToUser($user, $questionnaireTemplate);
    }

    /**
     * Apply a visible template onto a dossier.
     */
    public function apply(User $user, QuestionnaireTemplate $questionnaireTemplate): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, QuestionnaireTemplatePermission::Apply)
            && $this->isVisibleToUser($user, $questionnaireTemplate);
    }

    /**
     * System templates are read-only, and a firm may only edit its own.
     */
    private function isEditableFirmTemplate(User $user, QuestionnaireTemplate $template): bool
    {
        return ! $template->isSystem()
            && $this->ownsTenantTemplate($user, $template);
    }

    private function isVisibleToUser(User $user, QuestionnaireTemplate $template): bool
    {
        if ($template->isSystem()) {
            return true;
        }

        $tenant = $template->tenant;

        return $tenant instanceof Tenant && $user->canAccessTenant($tenant);
    }

    private function ownsTenantTemplate(User $user, QuestionnaireTemplate $template): bool
    {
        $tenant = Tenant::current();

        return $tenant instanceof Tenant
            && $template->tenant_id === $tenant->getKey()
            && $user->canAccessTenant($tenant);
    }
}
