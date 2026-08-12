<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Requests;

use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use App\Tenancy\Http\Requests\Concerns\ValidatesAssignableRolePermissions;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateTenantRoleRequest extends FormRequest
{
    use LocalizesValidationAttributes;
    use ValidatesAssignableRolePermissions;

    public function authorize(): bool
    {
        $role = $this->route('role');

        return $role instanceof TenantRole
            && ($this->user()?->can('update', $role) ?? false);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $tenant = $this->route('tenant');
        $role = $this->route('role');
        $teamForeignKey = Config::string('permission.column_names.team_foreign_key');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique(Config::string('permission.table_names.roles'), 'name')
                    ->ignore($role instanceof TenantRole ? $role->id : null)
                    ->where($teamForeignKey, $tenant instanceof Tenant ? $tenant->id : null)
                    ->where('guard_name', 'web'),
            ],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => [
                'required',
                'string',
                Rule::in($this->assignablePermissionValues()),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $role = $this->route('role');

            if ($role instanceof TenantRole && $role->is_system) {
                $validator->errors()->add(
                    'role',
                    __('System roles cannot be edited.'),
                );
            }
        });
    }

    public function name(): string
    {
        return mb_trim((string) $this->validated('name'));
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        /** @var list<string> $permissions */
        $permissions = array_values(array_unique($this->validated('permissions') ?? []));

        return $permissions;
    }
}
