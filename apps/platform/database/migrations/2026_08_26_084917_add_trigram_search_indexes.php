<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make Scout's database-engine searches indexable.
 *
 * SCOUT_DRIVER=database, so every search box compiles to
 * `... ilike '%term%'` across the model's toSearchableArray() columns
 * (Laravel\Scout\Engines\DatabaseEngine). A leading wildcard means no btree
 * index can serve it, so each search sequentially scanned the table — and for
 * dossiers the scan spans a join to `clients`, because Dossier::newScoutQuery()
 * left-joins it so a dossier can be found by its client's name.
 *
 * pg_trgm's GIN operator class indexes exactly that pattern. Measured on
 * 150k clients with the two-column OR the engine emits: 2.1 ms via a BitmapOr
 * over the two trigram indexes, versus 84 ms for the parallel sequential scan
 * without them. The gap widens linearly, because only the scan is O(rows).
 *
 * The columns below are the complete set the engine actually ILIKEs, read off
 * each model's toSearchableArray():
 *
 *   dossiers               title, reference   (+ clients.name via the join)
 *   clients                name, email
 *   document_requests      title, instructions
 *   questionnaire_templates name, description
 *   activity_log           description
 *
 * Two things this does NOT do, deliberately:
 *
 * - activity_log.event is left alone. It is also ILIKEd, but it holds short
 *   enum-like values already covered by the (tenant_id, event) btree for the
 *   equality filter that matters.
 * - It does not lift the two-phase ceiling in ScoutSearchFilter, which asks the
 *   engine for at most scout.key_limit (1000) keys and re-applies them with
 *   whereKey(). Past 1000 matches the result set is silently truncated and the
 *   paginator's total is wrong. That is a correctness limit, not a speed one,
 *   and it needs a different fix.
 *
 * Trigram indexes need a search term of at least three characters to help;
 * shorter terms still fall back to a scan.
 *
 * Postgres only. The test suite runs SQLite :memory: with SCOUT_DRIVER=collection
 * (phpunit.xml), which has neither the extension nor the operator class.
 *
 * Built without CONCURRENTLY because no environment carries production data
 * yet. Applying these to a live table later should use CREATE INDEX
 * CONCURRENTLY and `public $withinTransaction = false`.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private const array TRIGRAM_COLUMNS = [
        'dossiers' => ['title', 'reference'],
        'clients' => ['name', 'email'],
        'document_requests' => ['title', 'instructions'],
        'questionnaire_templates' => ['name', 'description'],
        'activity_log' => ['description'],
    ];

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('create extension if not exists pg_trgm');

        foreach (self::TRIGRAM_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement(sprintf(
                    'create index if not exists %s on %s using gin (%s gin_trgm_ops)',
                    $this->indexName($table, $column),
                    $table,
                    $column,
                ));
            }
        }
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        foreach (self::TRIGRAM_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement('drop index if exists '.$this->indexName($table, $column));
            }
        }

        // The extension is left in place: dropping it would take any other
        // trigram index with it, and it costs nothing to keep.
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function indexName(string $table, string $column): string
    {
        return sprintf('%s_%s_trgm_index', $table, $column);
    }
};
