<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Detect a magic link being redeemed from somewhere new.
 *
 * A grant token stays re-exchangeable until it expires (up to 90 days) — a
 * deliberate tradeoff, because clients lose emails and open links on several
 * devices. What was missing was any way to notice that a leaked link is being
 * used by someone else.
 *
 * Only a hash is stored, never the user agent or IP itself: this is a signal
 * about a credential, not a record of where a client lives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_access_grants', function (Blueprint $table): void {
            $table->string('device_fingerprint', 64)->nullable()->after('last_used_at');

            // Doubles as the "already told someone" flag, so one grant raises at
            // most one alert no matter how many devices open the link.
            $table->timestamp('new_device_seen_at')->nullable()->after('device_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('client_access_grants', function (Blueprint $table): void {
            $table->dropColumn(['device_fingerprint', 'new_device_seen_at']);
        });
    }
};
