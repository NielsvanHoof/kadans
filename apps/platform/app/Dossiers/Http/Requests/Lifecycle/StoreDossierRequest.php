<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\Lifecycle;

use App\Dossiers\Models\Dossier;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Queries\Settings\ResolveTenantSettingsQuery;
use App\Tenancy\Support\Settings\FirmSettingBounds;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Override;
use RuntimeException;

final class StoreDossierRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    /**
     * Authorize create via DossierPolicy so POST /dossiers cannot skip the create gate.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Dossier::class) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'client_id' => [
                'required',
                'integer',
                Rule::exists('clients', 'id')
                    ->where('tenant_id', $this->tenantId()),
            ],
            'title' => ['required', 'string', 'max:255'],
            'reference' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique((new Dossier)->getTable(), 'reference')
                    ->where('tenant_id', $this->tenantId()),
            ],
            'due_date' => ['nullable', 'date'],
            'responsible_user_id' => [
                'nullable',
                'integer',
                Rule::exists((new TenantMembership)->getTable(), 'user_id')
                    ->where('tenant_id', $this->tenantId())
                    ->where('status', TenantMembershipStatus::Active->value),
            ],
            'reminder_interval_days' => FirmSettingBounds::reminderIntervalDays()->rules(),
        ];
    }

    /**
     * Fall back to the firm's house rhythm when the caller said nothing at all.
     *
     * Absent and empty are different answers here. An absent key means the
     * caller expressed no preference, so the firm default applies. A key that
     * is present but empty means "no reminders on this dossier", which must
     * survive untouched — filling it would turn reminders on for a dossier
     * whose author deliberately turned them off.
     */
    #[Override]
    protected function prepareForValidation(): void
    {
        if ($this->has('reminder_interval_days')) {
            return;
        }

        $default = resolve(ResolveTenantSettingsQuery::class)->defaultReminderIntervalDays();

        if ($default !== null) {
            $this->merge(['reminder_interval_days' => $default]);
        }
    }

    private function tenantId(): int
    {
        $tenant = Tenant::current();

        throw_unless($tenant instanceof Tenant, RuntimeException::class, 'Cannot validate a dossier without an active tenant.');

        return $tenant->id;
    }
}
