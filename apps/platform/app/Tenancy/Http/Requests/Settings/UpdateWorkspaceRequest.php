<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Requests\Settings;

use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\Settings\ResolveTenantSettingsQuery;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Override;

use function is_string;

final class UpdateWorkspaceRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        $tenant = $this->route('tenant');

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $settings = resolve(ResolveTenantSettingsQuery::class)->forTenant($tenant);

        return $this->user()?->can('update', $settings) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $tenant = $this->route('tenant');

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('tenants', 'slug')->ignore(
                    $tenant instanceof Tenant ? $tenant->getKey() : null,
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function messages(): array
    {
        return [
            'slug.unique' => __('Another firm already uses that web address. Try a different one.'),
            'slug.required' => __('Enter a web address using letters, numbers, and hyphens.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function attributes(): array
    {
        return [
            'name' => __('Firm name'),
            'slug' => __('Web address'),
        ];
    }

    /**
     * Normalising before validation, not after, so the uniqueness check runs
     * against the value that will actually be stored. A firm typing its own
     * name into the address field gets a usable address rather than an error.
     */
    #[Override]
    protected function prepareForValidation(): void
    {
        $slug = $this->input('slug');

        if (is_string($slug)) {
            $this->merge(['slug' => Str::slug($slug)]);
        }
    }
}
