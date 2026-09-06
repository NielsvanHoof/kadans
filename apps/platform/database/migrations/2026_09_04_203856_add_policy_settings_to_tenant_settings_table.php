<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-firm policy overrides.
 *
 * Every column is nullable and null means "inherit the platform config value",
 * so no backfill is needed and a firm opts in one setting at a time.
 *
 * Bump TenantSettingsCache::SCHEMA_VERSION alongside this migration: a cached
 * attribute array written before it lacks these keys, and a missing key reads
 * as null — which this design defines as "inherit". Without the bump a firm's
 * override would be silently dropped for the rest of the cache TTL rather than
 * failing loudly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->string('two_factor_enforcement')->nullable();
            $table->unsignedSmallInteger('two_factor_grace_days')->nullable();
            $table->unsignedSmallInteger('portal_session_idle_minutes')->nullable();
            $table->unsignedSmallInteger('portal_session_absolute_minutes')->nullable();
            $table->unsignedSmallInteger('default_reminder_interval_days')->nullable();
            $table->unsignedSmallInteger('retention_archived_days')->nullable();
            $table->unsignedSmallInteger('retention_activity_log_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'two_factor_enforcement',
                'two_factor_grace_days',
                'portal_session_idle_minutes',
                'portal_session_absolute_minutes',
                'default_reminder_interval_days',
                'retention_archived_days',
                'retention_activity_log_days',
            ]);
        });
    }
};
