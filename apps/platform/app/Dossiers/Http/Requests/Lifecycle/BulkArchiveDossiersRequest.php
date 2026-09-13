<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\Lifecycle;

use App\Dossiers\Models\Dossier;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use App\Tenancy\Models\Tenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RuntimeException;

final class BulkArchiveDossiersRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        return $this->user()?->can('deleteAny', Dossier::class) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100', 'distinct'],
            'ids.*' => [
                'required',
                'integer',
                // Active dossiers only (exclude soft-deleted rows).
                Rule::exists((new Dossier)->getTable(), 'id')
                    ->where('tenant_id', $this->tenantId())
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return list<int>
     */
    public function dossierIds(): array
    {
        /** @var list<int> $ids */
        $ids = array_map(intval(...), $this->validated('ids'));

        return $ids;
    }

    private function tenantId(): int
    {
        $tenant = Tenant::current();

        throw_unless($tenant instanceof Tenant, RuntimeException::class, 'Cannot validate dossiers without an active tenant.');

        return $tenant->id;
    }
}
