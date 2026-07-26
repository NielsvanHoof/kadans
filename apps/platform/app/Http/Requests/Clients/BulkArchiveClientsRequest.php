<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use App\Http\Requests\Concerns\LocalizesValidationAttributes;
use App\Models\Client;
use App\Models\Tenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RuntimeException;

final class BulkArchiveClientsRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Client::class) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100', 'distinct'],
            'ids.*' => [
                'required',
                'integer',
                // Active clients only (exclude soft-deleted rows).
                Rule::exists((new Client)->getTable(), 'id')
                    ->where('tenant_id', $this->tenantId())
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return list<int>
     */
    public function clientIds(): array
    {
        /** @var list<int> $ids */
        $ids = array_map('intval', $this->validated('ids'));

        return $ids;
    }

    private function tenantId(): int
    {
        $tenant = Tenant::current();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('Cannot validate clients without an active tenant.');
        }

        return $tenant->id;
    }
}
