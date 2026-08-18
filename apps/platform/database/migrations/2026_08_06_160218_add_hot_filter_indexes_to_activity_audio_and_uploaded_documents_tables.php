<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add indexes for hot filtered / joined columns on request paths.
 *
 * PostgreSQL does not auto-index foreign keys, and exact filters on
 * activity_log.event / causer_id were full scans. Audio and uploaded
 * documents lag sibling tables that already use tenant-leading composites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            // Tenant-leading: FetchActivityLogIndexQuery always scopes by tenant_id.
            $table->index(['tenant_id', 'event']);
            $table->index(['tenant_id', 'causer_id']);
        });

        Schema::table('audio_recordings', function (Blueprint $table): void {
            // Match dossier_exports / document_requests / pipelines.
            $table->index(['tenant_id', 'dossier_id']);
            $table->index(['tenant_id', 'transcription_status']);
        });

        Schema::table('uploaded_documents', function (Blueprint $table): void {
            // Parallel to document_requests (tenant_id, status).
            $table->index(['tenant_id', 'processing_status']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'event']);
            $table->dropIndex(['tenant_id', 'causer_id']);
        });

        Schema::table('audio_recordings', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'dossier_id']);
            $table->dropIndex(['tenant_id', 'transcription_status']);
        });

        Schema::table('uploaded_documents', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'processing_status']);
        });
    }
};
