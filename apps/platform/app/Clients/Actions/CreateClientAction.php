<?php

declare(strict_types=1);

namespace App\Clients\Actions;

use App\Clients\Models\Client;

/**
 * Persist a new client for the current tenant.
 */
final class CreateClientAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): Client
    {
        return Client::query()->create($attributes);
    }
}
