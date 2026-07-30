<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks which roles are owned by code (App\Tenancy\Enums\Role) rather than by a firm.
 *
 * SyncTenantRolesAction reconciles system roles on every deploy, so tenant-authored
 * roles have to be distinguishable from them before either can be managed safely.
 */
return new class extends Migration
{
    /**
     * System role names as they stand at this migration.
     *
     * Deliberately hardcoded rather than read from Role::values(): a migration
     * that reads current application code rewrites history the moment a case
     * is renamed.
     *
     * @var list<string>
     */
    private const array SYSTEM_ROLE_NAMES = [
        'owner',
        'administrator',
        'adviser',
        'reviewer',
        'read-only',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_system')->default(false);
            $table->index(['tenant_id', 'is_system']);
        });

        DB::table('roles')
            ->whereIn('name', self::SYSTEM_ROLE_NAMES)
            ->update(['is_system' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_system']);
            $table->dropColumn('is_system');
        });
    }
};
