<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keyword + vector search columns for uploaded document OCR contents.
 *
 * searchable_text is a denormalised flatten of filename + extraction JSON so
 * Scout's database engine can ILIKE real columns (it does not search computed
 * PHP). embedding is Titan V2 (1024 dims) of the overview only.
 *
 * Postgres: pg_trgm GIN on searchable_text and an HNSW vector index.
 * SQLite tests: text/json stand-ins; SCOUT_DRIVER=collection never reads them
 * as pgvector. Same portability guard as 2026_08_26_084917.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploaded_documents', function (Blueprint $table): void {
            $table->longText('searchable_text')->nullable();
        });

        if ($this->isPostgres()) {
            Schema::ensureVectorExtensionExists();

            Schema::table('uploaded_documents', function (Blueprint $table): void {
                $table->vector('embedding', dimensions: 1024)->nullable();
                $table->vectorIndex('embedding');
            });

            DB::statement('create extension if not exists pg_trgm');
            DB::statement(
                'create index if not exists uploaded_documents_searchable_text_trgm_index on uploaded_documents using gin (searchable_text gin_trgm_ops)',
            );

            return;
        }

        Schema::table('uploaded_documents', function (Blueprint $table): void {
            $table->json('embedding')->nullable();
        });
    }

    public function down(): void
    {
        if ($this->isPostgres()) {
            DB::statement('drop index if exists uploaded_documents_searchable_text_trgm_index');
        }

        Schema::table('uploaded_documents', function (Blueprint $table): void {
            $table->dropColumn(['searchable_text', 'embedding']);
        });
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
