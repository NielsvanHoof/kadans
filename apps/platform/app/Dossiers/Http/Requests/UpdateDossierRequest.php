<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests;

use App\Dossiers\Models\Dossier;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RuntimeException;

final class UpdateDossierRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        $dossier = $this->route('dossier');

        return $dossier instanceof Dossier
            && ($this->user()?->can('update', $dossier) ?? false);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $dossier = $this->route('dossier');

        if (! $dossier instanceof Dossier) {
            throw new RuntimeException('Cannot update a dossier without route model binding.');
        }

        return [
            'title' => ['required', 'string', 'max:255'],
            'reference' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique((new Dossier)->getTable(), 'reference')
                    ->where('tenant_id', $this->tenantId())
                    ->ignore($dossier),
            ],
            'due_date' => ['nullable', 'date'],
            'responsible_user_id' => [
                'nullable',
                'integer',
                Rule::exists((new TenantMembership)->getTable(), 'user_id')
                    ->where('tenant_id', $this->tenantId())
                    ->where('status', TenantMembershipStatus::Active->value),
            ],
            'reminder_interval_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }

    private function tenantId(): int
    {
        $tenant = Tenant::current();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('Cannot validate a dossier without an active tenant.');
        }

        return $tenant->id;
    }
}
