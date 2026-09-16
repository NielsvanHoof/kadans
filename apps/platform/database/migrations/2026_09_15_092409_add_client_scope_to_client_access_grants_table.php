<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let one magic link cover every dossier a firm has shared with a client.
 *
 * A grant keeps pointing at its client either way, so `client_id` is not
 * nullable; `dossier_id` becomes nullable and is set only for the per-dossier
 * links that already exist. `scope` records which of the two a row is, because
 * the reachability chokepoint matches exhaustively on it.
 *
 * Ordering matters. Both `change()` calls run before the new constraints are
 * added, because SQLite implements a column change by rebuilding the table —
 * doing it first means the rebuild only has to carry the original schema
 * across, not constraints this migration just added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_access_grants', function (Blueprint $table): void {
            $table->unsignedBigInteger('client_id')->nullable()->after('tenant_id');
            // Enum-like string — cast to ClientAccessGrantScope in the model.
            $table->string('scope', 16)->default('dossier')->after('client_id');
        });

        // Correlated subquery rather than a chunked PHP loop: portable to both
        // drivers and one statement on a table that is pruned to 90 days.
        //
        // Deliberately no `deleted_at` filter on dossiers. A grant whose dossier
        // was archived still has to learn its client, or it would fail the NOT
        // NULL below and take the whole migration with it.
        DB::statement(<<<'SQL'
            update client_access_grants
            set client_id = (
                select dossiers.client_id
                from dossiers
                where dossiers.id = client_access_grants.dossier_id
            )
        SQL);

        // Every existing row had a NOT NULL dossier_id under a live foreign key,
        // so none of these can be null unless something is wrong with the data
        // we are about to constrain. Fail here, where the transaction can still
        // be rolled back, rather than on the NOT NULL a line later.
        $orphaned = DB::table('client_access_grants')->whereNull('client_id')->count();

        throw_if(
            $orphaned > 0,
            RuntimeException::class,
            "Cannot add client scope: {$orphaned} grant(s) have no resolvable client.",
        );

        Schema::table('client_access_grants', function (Blueprint $table): void {
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
            $table->unsignedBigInteger('dossier_id')->nullable()->change();
        });

        Schema::table('client_access_grants', function (Blueprint $table): void {
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();

            // The guarantee that makes a cross-tenant grant unrepresentable,
            // matching the one dossier_id already carries.
            $table->foreign(['tenant_id', 'client_id'])
                ->references(['tenant_id', 'id'])
                ->on('clients')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('client_access_grants', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'client_id']);
            $table->dropForeign(['client_id']);
            $table->dropIndex(['tenant_id', 'client_id']);
        });

        // Client-scoped grants have no dossier to fall back to, so they cannot
        // survive a column that is about to be NOT NULL again.
        DB::table('client_access_grants')->whereNull('dossier_id')->delete();

        Schema::table('client_access_grants', function (Blueprint $table): void {
            $table->unsignedBigInteger('dossier_id')->nullable(false)->change();
        });

        Schema::table('client_access_grants', function (Blueprint $table): void {
            $table->dropColumn(['client_id', 'scope']);
        });
    }
};
