<?php

declare(strict_types=1);

namespace App\Clients\Http\Requests;

use App\Clients\Models\Client;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use App\Tenancy\Models\Tenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RuntimeException;

use function intval;

final class BulkRestoreClientsRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        return $this->user()?->can('restoreAny', Client::class) ?? false;
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
                Rule::exists((new Client)->getTable(), 'id')
                    ->where('tenant_id', $this->tenantId())
                    ->whereNotNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return list<int>
     */
    public function clientIds(): array
    {
        /** @var list<int> $ids */
        $ids = array_map(intval(...), $this->validated('ids'));

        return $ids;
    }

    private function tenantId(): int
    {
        $tenant = Tenant::current();

        throw_unless($tenant instanceof Tenant, RuntimeException::class, 'Cannot validate clients without an active tenant.');

        return $tenant->id;
    }
}
