<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Accounts\Models\User;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

use function array_key_exists;

/**
 * @extends Factory<TenantMembership>
 */
final class TenantMembershipFactory extends Factory
{
    #[Override]
    protected $model = TenantMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_title' => fake()->jobTitle(),
            'last_accessed_at' => now(),
        ];
    }

    #[Override]
    public function configure(): static
    {
        return $this->afterMaking(function (TenantMembership $membership, array $attributes): void {
            $membership->forceFill([
                'tenant_id' => $attributes['tenant_id'] ?? Tenant::factory()->create()->id,
                'user_id' => $attributes['user_id'] ?? User::factory()->create()->id,
                'status' => $attributes['status'] ?? TenantMembershipStatus::Active,
                'invited_at' => $attributes['invited_at'] ?? now(),
                'joined_at' => array_key_exists('joined_at', $attributes)
                    ? $attributes['joined_at']
                    : now(),
            ]);
        });
    }

    public function invited(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TenantMembershipStatus::Invited,
            'joined_at' => null,
        ]);
    }
}
