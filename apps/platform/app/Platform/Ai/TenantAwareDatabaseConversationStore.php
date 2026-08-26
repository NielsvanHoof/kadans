<?php

declare(strict_types=1);

namespace App\Platform\Ai;

use App\Tenancy\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Override;
use RuntimeException;

/**
 * Stamps tenant_id on Laravel AI conversation rows and scopes every lookup.
 *
 * DatabaseConversationStore writes through DB::table(), so BelongsToTenant
 * creating hooks never fire. This wrapper is a singleton (matching the vendor
 * binding) and must not cache tenant_id on the instance — Octane reuses it.
 */
final class TenantAwareDatabaseConversationStore extends DatabaseConversationStore
{
    #[Override]
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string
    {
        $conversationId = (string) Str::uuid7();

        $this->table($this->conversationsTable())->insert([
            'id' => $conversationId,
            'tenant_id' => $this->currentTenantId(),
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $conversationId;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    #[Override]
    protected function messageAttributes(string $messageId, string $conversationId, ?string $participantType, string|int|null $participantId, mixed $now, array $attributes): array
    {
        return array_merge(parent::messageAttributes($messageId, $conversationId, $participantType, $participantId, $now, $attributes), [
            'tenant_id' => $this->currentTenantId(),
        ]);
    }

    #[Override]
    protected function table(string $table): Builder
    {
        return parent::table($table)->where('tenant_id', $this->currentTenantId());
    }

    private function currentTenantId(): int
    {
        $tenant = Tenant::current();

        throw_unless($tenant instanceof Tenant, RuntimeException::class, 'Cannot create tenant-owned records without an active tenant.');

        return (int) $tenant->getKey();
    }
}
