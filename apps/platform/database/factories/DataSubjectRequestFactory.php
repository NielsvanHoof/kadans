<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Platform\Enums\DataSubjectRequestStatus;
use App\Platform\Enums\DataSubjectRequestType;
use App\Platform\Models\DataSubjectRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<DataSubjectRequest>
 */
final class DataSubjectRequestFactory extends Factory
{
    #[Override]
    protected $model = DataSubjectRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $requestedAt = now();

        return [
            'client_id' => Client::factory(),
            'type' => DataSubjectRequestType::Export,
            'status' => DataSubjectRequestStatus::Pending,
            'requested_at' => $requestedAt,
            'due_at' => $requestedAt->copy()->addDays((int) config('retention.data_subject_response_days')),
            'requested_by' => User::factory(),
        ];
    }

    public function erasure(): static
    {
        return $this->state(fn (): array => [
            'type' => DataSubjectRequestType::Erasure,
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (): array => [
            'due_at' => now()->subDay(),
        ]);
    }

    #[Override]
    public function configure(): static
    {
        return $this->afterMaking(function (DataSubjectRequest $request): void {
            if ($request->tenant_id !== null) {
                return;
            }

            // Mirrors the other tenant-owned factories: inherit the tenant from
            // the parent record rather than provisioning a second one.
            $client = Client::queryWithoutTenantScope()->find($request->client_id);

            if ($client instanceof Client) {
                $request->tenant_id = $client->tenant_id;
            }
        });
    }
}
