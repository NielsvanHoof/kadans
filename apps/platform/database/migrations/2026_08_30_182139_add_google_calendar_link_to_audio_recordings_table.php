<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendar event links for audio transcripts (Google now, Microsoft later).
 * One recording may link to many events across providers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_recording_calendar_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audio_recording_id')->constrained()->cascadeOnDelete();
            // Enum-like string — cast to OAuthProvider in the model.
            $table->string('provider');
            $table->string('external_event_id');
            $table->text('external_event_url')->nullable();
            // Drive / OneDrive file id for the uploaded transcript attachment.
            $table->string('external_file_id')->nullable();
            $table->foreignId('linked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            // Same calendar event must not be attached twice to one recording.
            $table->unique(['audio_recording_id', 'provider', 'external_event_id']);
            $table->foreign(['tenant_id', 'audio_recording_id'])
                ->references(['tenant_id', 'id'])
                ->on('audio_recordings')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_recording_calendar_links');
    }
};
