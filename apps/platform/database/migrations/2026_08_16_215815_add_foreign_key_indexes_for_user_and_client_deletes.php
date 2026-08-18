<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the foreign keys that a parent delete has to scan.
 *
 * PostgreSQL does not index foreign keys automatically. Users hard-delete —
 * `User` has no SoftDeletes and the table has no deleted_at — so the
 * self-service `settings/profile` DELETE fires every referencing constraint at
 * once. Twelve of those columns appeared in no index at all, which made the
 * cascade a sequential scan of the widest tables in the schema, including
 * uploaded_documents, document_requests and pipelines.
 *
 * A sequential scan reads every firm's rows, so the cost of one account
 * deletion grew with the size of the whole platform rather than the deleting
 * user's workspace. Account deletion is a statutory erasure path; it has to
 * stay predictable as the data grows.
 *
 * data_subject_requests.client_id rides along for the same reason on the
 * clients cascade, reached by client deletion and the retention purge.
 *
 * These are deliberately single-column, not tenant-leading composites. None of
 * these columns is ever a query filter — they are only written and selected —
 * so the index exists solely for referential integrity, and a constraint check
 * matches on the foreign key alone. Putting tenant_id first would leave the
 * cascade back where it started.
 */
return new class extends Migration
{
    /**
     * Foreign key columns to index, grouped by table.
     *
     * @var array<string, list<string>>
     */
    private const array INDEXED_FOREIGN_KEYS = [
        'audio_recordings' => ['uploaded_by'],
        'client_access_grants' => ['created_by'],
        'data_subject_requests' => ['requested_by', 'completed_by', 'refused_by', 'client_id'],
        'document_requests' => ['reviewed_by'],
        'dossier_exports' => ['requested_by'],
        'dossiers' => ['responsible_user_id', 'builder_locked_by_user_id'],
        'pipelines' => ['initiated_by'],
        'tenant_invitations' => ['invited_by'],
        'uploaded_documents' => ['reviewed_by'],
    ];

    public function up(): void
    {
        foreach (self::INDEXED_FOREIGN_KEYS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column) {
                    $blueprint->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXED_FOREIGN_KEYS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns): void {
                foreach ($columns as $column) {
                    $blueprint->dropIndex("{$table}_{$column}_index");
                }
            });
        }
    }
};
