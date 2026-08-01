<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exclusive questionnaire-builder edit lease on a dossier.
 *
 * Idle expiry is enforced via builder_lock_expires_at (heartbeat extends it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers', function (Blueprint $table): void {
            $table->foreignId('builder_locked_by_user_id')
                ->nullable()
                ->after('last_client_opened_at')
                ->constrained('users')
                ->nullOnDelete();
            // Opaque token so heartbeat/release only succeed for the holder session.
            $table->string('builder_lock_token', 26)->nullable()->after('builder_locked_by_user_id');
            $table->timestamp('builder_lock_expires_at')->nullable()->after('builder_lock_token');
        });
    }

    public function down(): void
    {
        Schema::table('dossiers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('builder_locked_by_user_id');
            $table->dropColumn(['builder_lock_token', 'builder_lock_expires_at']);
        });
    }
};
