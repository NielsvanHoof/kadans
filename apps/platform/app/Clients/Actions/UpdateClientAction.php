<?php

declare(strict_types=1);

namespace App\Clients\Actions;

use App\Clients\Models\Client;

/**
 * Persist updated client profile attributes.
 */
final class UpdateClientAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Client $client, array $attributes): void
    {
        $client->update($attributes);
    }
}
