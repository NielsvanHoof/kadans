<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreate Laravel AI conversation tables before they take traffic.
 *
 * The published vendor schema had no tenant_id, varchar(36) PKs, JSON stored
 * as TEXT, and no FK from messages to conversations. Both tables are empty
 * (no agent uses RemembersConversations yet), so drop-and-recreate is safer
 * than a portable ALTER across Postgres and SQLite.
 *
 * UUID v7 stays: DatabaseConversationStore generates string ids and returns
 * them as conversationId. Native uuid shrinks the Postgres index vs char(36).
 *
 * Indexes match the store predicates (latestConversationId, getLatest by
 * conversation_id). Volume is zero, so there is no EXPLAIN ANALYZE to record.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conversationsTable = $this->conversationsTable();
        $messagesTable = $this->messagesTable();

        Schema::dropIfExists($messagesTable);
        Schema::dropIfExists($conversationsTable);

        Schema::create($conversationsTable, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('participant_type')->nullable();
            $table->unsignedBigInteger('participant_id')->nullable();
            $table->string('title');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(
                ['tenant_id', 'participant_type', 'participant_id', 'updated_at'],
                'participant_updated_at_index',
            );
        });

        Schema::create($messagesTable, function (Blueprint $table) use ($conversationsTable): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('conversation_id')->index();
            $table->string('participant_type')->nullable();
            $table->unsignedBigInteger('participant_id')->nullable();
            $table->string('agent');
            $table->string('role', 25);
            $table->text('content');
            $table->json('attachments');
            $table->json('tool_calls');
            $table->json('tool_results');
            $table->json('usage');
            $table->json('meta');
            $table->json('approval_state')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'conversation_id'])
                ->references(['tenant_id', 'id'])
                ->on($conversationsTable)
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $conversationsTable = $this->conversationsTable();
        $messagesTable = $this->messagesTable();

        Schema::dropIfExists($messagesTable);
        Schema::dropIfExists($conversationsTable);

        Schema::create($conversationsTable, function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('participant_type')->nullable();
            $table->unsignedBigInteger('participant_id')->nullable();
            $table->string('title');
            $table->timestamps();

            $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
        });

        Schema::create($messagesTable, function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36)->index();
            $table->string('participant_type')->nullable();
            $table->unsignedBigInteger('participant_id')->nullable();
            $table->string('agent');
            $table->string('role', 25);
            $table->text('content');
            $table->text('attachments');
            $table->text('tool_calls');
            $table->text('tool_results');
            $table->text('usage');
            $table->text('meta');
            $table->text('approval_state')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'participant_type', 'participant_id', 'updated_at'], 'conversation_index');
            $table->index(['participant_type', 'participant_id'], 'participant_index');
        });
    }

    private function conversationsTable(): string
    {
        $table = config('ai.conversations.tables.conversations', 'agent_conversations');

        return is_string($table) ? $table : 'agent_conversations';
    }

    private function messagesTable(): string
    {
        $table = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        return is_string($table) ? $table : 'agent_conversation_messages';
    }
};
