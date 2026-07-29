<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests;

use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use App\Tenancy\Models\Tenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use RuntimeException;

final class BulkRestoreDossiersRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        return $this->user()?->can('restoreAny', Dossier::class) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100', 'distinct'],
            'ids.*' => [
                'required',
                'integer',
                // onlyTrashed-style: must be soft-deleted in this tenant.
                Rule::exists((new Dossier)->getTable(), 'id')
                    ->where('tenant_id', $this->tenantId())
                    ->whereNotNull('deleted_at'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var list<int|string> $ids */
            $ids = $this->input('ids', []);

            $dossiersQuery = Dossier::onlyTrashed();
            $dossiersQuery->getQuery()->whereIn('id', $ids);
            $dossiersQuery->getQuery()->whereIn(
                'client_id',
                Client::onlyTrashed()->toBase()->select('id'),
            );

            if ($dossiersQuery->toBase()->exists()) {
                $validator->errors()->add(
                    'ids',
                    __('Restore the client before restoring this dossier.'),
                );
            }
        });
    }

    /**
     * @return list<int>
     */
    public function dossierIds(): array
    {
        /** @var list<int> $ids */
        $ids = array_map('intval', $this->validated('ids'));

        return $ids;
    }

    private function tenantId(): int
    {
        $tenant = Tenant::current();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('Cannot validate dossiers without an active tenant.');
        }

        return $tenant->id;
    }
}
