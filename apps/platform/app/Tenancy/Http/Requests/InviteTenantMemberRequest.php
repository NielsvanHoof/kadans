<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Requests;

use App\Accounts\Models\User;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Support\WorkspaceMemberRoles;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Override;

use function is_string;

final class InviteTenantMemberRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        return $this->user()?->can('create', TenantInvitation::class) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::enum(Role::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function attributes(): array
    {
        return [
            'email' => __('Email address'),
            'role' => __('Role'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();
            $tenant = Tenant::current();
            $roleValue = $this->input('role');

            if (! $user instanceof User || ! $tenant instanceof Tenant || ! is_string($roleValue)) {
                return;
            }

            $role = Role::tryFrom($roleValue);

            if ($role === null) {
                return;
            }

            $memberRoles = resolve(WorkspaceMemberRoles::class);

            if (! $memberRoles->actorCanAssignRole($user, $tenant, $role)) {
                $validator->errors()->add(
                    'role',
                    __('Only an owner can assign the owner role.'),
                );
            }
        });
    }

    public function role(): Role
    {
        return Role::from((string) $this->validated('role'));
    }

    public function email(): string
    {
        return mb_strtolower(mb_trim((string) $this->validated('email')));
    }
}
