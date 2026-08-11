<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Clients\Models\Client;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<Client>
 */
final class ClientFactory extends Factory
{
    #[Override]
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [ 
            'tenant_id' => fn (): mixed => Tenant::current()?->getKey() ?? Tenant::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
