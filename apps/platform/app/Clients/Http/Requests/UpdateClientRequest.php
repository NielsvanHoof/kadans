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

final class UpdateClientRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        $client = $this->route('client');

        return $client instanceof Client
            && ($this->user()?->can('update', $client) ?? false);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $client = $this->route('client');

        throw_unless($client instanceof Client, RuntimeException::class, 'Cannot update a client without route model binding.');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique((new Client)->getTable(), 'email')
                    ->where('tenant_id', $this->tenantId())
                    ->ignore($client),
            ],
        ];
    }

    private function tenantId(): int
    {
        $tenant = Tenant::current();

        throw_unless($tenant instanceof Tenant, RuntimeException::class, 'Cannot validate a client without an active tenant.');

        return $tenant->id;
    }
}
