<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Requests;

use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use App\Tenancy\Http\Requests\Concerns\ValidatesAssignableRolePermissions;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTenantRoleRequest extends FormRequest
{
    use LocalizesValidationAttributes;
    use ValidatesAssignableRolePermissions;

    public function authorize(): bool
    {
        return $this->user()?->can('create', TenantRole::class) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $tenant = $this->route('tenant');
        $teamForeignKey = (string) config('permission.column_names.team_foreign_key');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique(config('permission.table_names.roles'), 'name')
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
