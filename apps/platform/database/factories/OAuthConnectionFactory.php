<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Accounts\Models\User;
use App\Integrations\Enums\OAuthProvider;
use App\Integrations\Models\OAuthConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<OAuthConnection>
 */
final class OAuthConnectionFactory extends Factory
{
    /**
     * @var class-string<OAuthConnection>
     */
    #[Override]
    protected $model = OAuthConnection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => OAuthProvider::Microsoft,
            'provider_user_id' => (string) fake()->unique()->numerify('################'),
            'email' => fake()->safeEmail(),
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addHour(),
            'scopes' => OAuthProvider::Microsoft->scopes(),
        ];
    }

    public function forProvider(OAuthProvider $provider): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => $provider,
            'scopes' => $provider->scopes(),
        ]);
    }
}
