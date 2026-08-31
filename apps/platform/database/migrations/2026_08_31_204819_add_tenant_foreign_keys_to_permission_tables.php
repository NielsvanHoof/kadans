<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spatie teams stores tenant_id on roles / model_has_* without an FK.
 * Mirror the rest of the tenant-owned schema so orphan team rows cannot survive
 * a tenant delete.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
        });

        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
        });

        Schema::table('model_has_permissions', function (Blueprint $table): void {
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('model_has_permissions', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
        });

        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
        });
    }
};
