<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serves the dashboard's per-assignee lane and the dossier index's
 * `responsible` filter.
 *
 * responsible_user_id carries a foreign key but no index of its own: Postgres
 * indexes the referenced primary key, never the referencing column. Without
 * this composite, "dossiers assigned to me, soonest due first" scans the whole
 * tenant partition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers', function (Blueprint $table): void {
            $table->index(
                ['tenant_id', 'responsible_user_id', 'due_date'],
                'dossiers_tenant_responsible_due_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('dossiers', function (Blueprint $table): void {
            $table->dropIndex('dossiers_tenant_responsible_due_index');
        });
    }
};
