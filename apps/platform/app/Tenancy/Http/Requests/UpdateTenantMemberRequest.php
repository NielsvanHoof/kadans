<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Requests;

use App\Accounts\Models\User;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Support\WorkspaceMemberRoles;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Override;

use function is_string;

final class UpdateTenantMemberRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        $membership = $this->route('membership');

        return $membership instanceof TenantMembership
            && ($this->user()?->can('update', $membership) ?? false);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $tenant = $this->route('tenant');
        $teamForeignKey = (string) config('permission.column_names.team_foreign_key');

        return [
            'role' => [
                'sometimes',
                'required',
                'string',
                Rule::exists(config('permission.table_names.roles'), 'name')
                    ->where($teamForeignKey, $tenant instanceof Tenant ? $tenant->id : null)
                    ->where('guard_name', 'web'),
            ],
            'status' => [
                'sometimes',
                'required',
                'string',
                Rule::in([
                    TenantMembershipStatus::Active->value,
                    TenantMembershipStatus::Suspended->value,
                ]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function attributes(): array
    {
        return [
            'role' => __('Role'),
            'status' => __('Status'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('role') && ! $this->filled('status')) {
                $validator->errors()->add(
                    'role',
                    __('Provide a role or status to update.'),
                );
            }

            $user = $this->user();
            $membership = $this->route('membership');
            $roleValue = $this->input('role');

            if (
                ! $user instanceof User
                || ! $membership instanceof TenantMembership
                || ! is_string($roleValue)
            ) {
                return;
            }

            if (! ($this->user()?->can('assignRole', [$membership, $roleValue]) ?? false)) {
                $validator->errors()->add(
                    'role',
                    __('Only an owner can assign the owner role.'),
                );

                return;
            }

            $memberRoles = resolve(WorkspaceMemberRoles::class);
            $tenant = $memberRoles->requireTenant($membership);
            $member = $membership->user;

            if (
                $member instanceof User
                && $memberRoles->roleFor($member, $tenant) === Role::Owner->value
                && $roleValue !== Role::Owner->value
                && $memberRoles->isLastActiveOwner($member, $tenant)
            ) {
                $validator->errors()->add(
                    'role',
                    __('The workspace must keep at least one owner.'),
                );
            }
        });
    }

    public function role(): ?string
    {
        $role = $this->validated('role') ?? null;

        return is_string($role) ? $role : null;
    }

    public function status(): ?TenantMembershipStatus
    {
        $status = $this->validated('status') ?? null;

        return is_string($status) ? TenantMembershipStatus::from($status) : null;
    }
}
