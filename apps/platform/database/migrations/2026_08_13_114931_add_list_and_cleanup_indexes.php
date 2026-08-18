<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Partial index for StuckPipelinesCheck. Laravel 13's compileIndex() does
     * not emit WHERE, so Blueprint->index()->where() would create a full index.
     */
    private const string PIPELINES_STUCK_INDEX = 'pipelines_stuck_id_index';

    public function up(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $table): void {
            // EnsureValidTenantMembership loads by user_id; the unique is (tenant_id, user_id).
            $table->index('user_id');
        });

        Schema::table('dossiers', function (Blueprint $table): void {
            // Default index sort is -updated_at; archived view sorts by deleted_at.
            $table->index(['tenant_id', 'updated_at']);
            $table->index(['tenant_id', 'deleted_at']);
        });

        Schema::table('clients', function (Blueprint $table): void {
            // Default index sort is name; archived view sorts by deleted_at.
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'deleted_at']);
        });

        Schema::table('pipelines', function (Blueprint $table): void {
            // Default sort has no status predicate, so (tenant_id, status, created_at) cannot serve it.
            $table->index(['tenant_id', 'created_at']);
        });

        DB::statement(sprintf(
            "create index %s on pipelines (id) where status = 'stuck'",
            self::PIPELINES_STUCK_INDEX,
        ));

        Schema::table('notifications', function (Blueprint $table): void {
            // retention:prune-notifications deletes WHERE created_at <= cutoff daily.
            $table->index('created_at');
        });

        Schema::table('document_requests', function (Blueprint $table): void {
            // Media library: type = file, tenant-scoped, sorted by updated_at.
            $table->index(['tenant_id', 'type', 'updated_at']);
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropIndex('roles_team_foreign_key_index');
        });

        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->dropIndex('model_has_roles_team_foreign_key_index');
        });

        Schema::table('model_has_permissions', function (Blueprint $table): void {
            $table->dropIndex('model_has_permissions_team_foreign_key_index');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->index('tenant_id', 'roles_team_foreign_key_index');
        });

        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->index('tenant_id', 'model_has_roles_team_foreign_key_index');
        });

        Schema::table('model_has_permissions', function (Blueprint $table): void {
            $table->index('tenant_id', 'model_has_permissions_team_foreign_key_index');
        });

        Schema::table('document_requests', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'type', 'updated_at']);
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });

        Schema::table('pipelines', function (Blueprint $table): void {
            $table->dropIndex(self::PIPELINES_STUCK_INDEX);
            $table->dropIndex(['tenant_id', 'created_at']);
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'name']);
            $table->dropIndex(['tenant_id', 'deleted_at']);
        });

        Schema::table('dossiers', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'updated_at']);
            $table->dropIndex(['tenant_id', 'deleted_at']);
        });

        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
        });
    }
};
