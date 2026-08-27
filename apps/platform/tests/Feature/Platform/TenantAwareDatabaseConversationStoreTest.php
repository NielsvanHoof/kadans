<?php

declare(strict_types=1);

use App\Platform\Ai\TenantAwareDatabaseConversationStore;
use App\Questionnaires\Ai\ChecklistDraftAgent;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;

covers(TenantAwareDatabaseConversationStore::class);

function conversationStore(): ConversationStore
{
    return resolve(ConversationStore::class);
}

function seedAgentMessage(string $conversationId, int $tenantId, string $content = 'Hello'): string
{
    $id = (string) Str::uuid7();

    DB::table('agent_conversation_messages')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => 1,
        'agent' => ChecklistDraftAgent::class,
        'role' => 'user',
        'content' => $content,
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'approval_state' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

test('the conversation store is the tenant-aware wrapper', function (): void {
    expect(conversationStore())->toBeInstanceOf(TenantAwareDatabaseConversationStore::class);
});

test('storing a conversation stamps the current tenant', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $conversationId = conversationStore()->storeConversation(
        $owner->getMorphClass(),
        $owner->getKey(),
        'Draft a reminder',
    );

    $row = DB::table('agent_conversations')->where('id', $conversationId)->first();

    expect($row)->not->toBeNull()
        ->and($row?->tenant_id)->toBe($tenant->id);
});

test('storing a user message stamps the current tenant', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $store = conversationStore();
    $conversationId = $store->storeConversation(
        $owner->getMorphClass(),
        $owner->getKey(),
        'Draft a reminder',
    );

    $prompt = new AgentPrompt(
        new ChecklistDraftAgent('English'),
        'Please draft a reminder',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );

    $messageId = $store->storeUserMessage(
        $conversationId,
        $owner->getMorphClass(),
        $owner->getKey(),
        $prompt,
    );

    $row = DB::table('agent_conversation_messages')->where('id', $messageId)->first();

    expect($row)->not->toBeNull()
        ->and($row?->tenant_id)->toBe($tenant->id)
        ->and($row?->conversation_id)->toBe($conversationId);
});

test('storing a conversation without a tenant fails closed', function (): void {
    provisionWorkspace();
    Tenant::forgetCurrent();

    expect(fn (): string => conversationStore()->storeConversation('user', 1, 'Hi'))
        ->toThrow(RuntimeException::class, 'Cannot create tenant-owned records without an active tenant.');
});

test('latest conversation id does not leak across tenants', function (): void {
    ['owner' => $owner, 'tenant' => $tenantA] = provisionWorkspace('Firm A');

    $idA = conversationStore()->storeConversation(
        $owner->getMorphClass(),
        $owner->getKey(),
        'Firm A thread',
    );

    ['tenant' => $tenantB] = provisionWorkspace('Firm B', $owner);

    expect($tenantB->id)->not->toBe($tenantA->id)
        ->and(conversationStore()->latestConversationId($owner->getMorphClass(), $owner->getKey()))
        ->toBeNull();

    $idB = conversationStore()->storeConversation(
        $owner->getMorphClass(),
        $owner->getKey(),
        'Firm B thread',
    );

    expect(conversationStore()->latestConversationId($owner->getMorphClass(), $owner->getKey()))
        ->toBe($idB);

    actingInWorkspace($tenantA);

    expect(conversationStore()->latestConversationId($owner->getMorphClass(), $owner->getKey()))
        ->toBe($idA);
});

test('conversation messages do not leak across tenants', function (): void {
    ['owner' => $owner, 'tenant' => $tenantA] = provisionWorkspace('Firm A');

    $store = conversationStore();
    $idA = $store->storeConversation(
        $owner->getMorphClass(),
        $owner->getKey(),
        'Firm A thread',
    );
    seedAgentMessage($idA, $tenantA->id, 'Secret to firm A');

    provisionWorkspace('Firm B', $owner);

    expect($store->getLatestConversationMessages($idA, 10))->toBeEmpty();

    actingInWorkspace($tenantA);

    $messages = $store->getLatestConversationMessages($idA, 10);

    expect($messages)->toHaveCount(1)
        ->and($messages->first()?->content)->toBe('Secret to firm A');
});

test('a message cannot attach to another tenant conversation', function (): void {
    ['tenant' => $tenantA] = provisionWorkspace('Firm A');

    $idA = conversationStore()->storeConversation('user', 1, 'Firm A thread');

    ['tenant' => $tenantB] = provisionWorkspace('Firm B');

    expect(fn (): string => seedAgentMessage($idA, $tenantB->id))
        ->toThrow(QueryException::class);
});
