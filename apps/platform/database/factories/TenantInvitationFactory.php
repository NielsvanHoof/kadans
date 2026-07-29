<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Accounts\Models\User;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Override;

/**
 * @extends Factory<TenantInvitation>
 */
final class TenantInvitationFactory extends Factory
{
    #[Override]
    protected $model = TenantInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => Role::Adviser,
            'token' => TenantInvitation::hashToken(Str::random(64)),
            'invited_by' => User::factory(),
            'expires_at' => now()->addDays(7),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'accepted_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
