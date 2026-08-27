<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the predicates that scan today.
 *
 * Each of these was verified against pg_indexes rather than inferred: the
 * tenant-leading composites added in 2026_08_06_160218 and 2026_08_13_114931
 * already cover the request-path filters, and these are the four predicates
 * left that have no index able to serve them.
 *
 * Deliberately NOT added, so the next reader does not re-derive them:
 *
 * - pipelines (tenant_id, status, last_activity_at), for MonitorPipelines'
 *   stuck detection. The predicate ORs last_activity_at against a started_at
 *   fallback, so no single index covers it and the planner ignored this one
 *   entirely: 2.90 ms via the existing (tenant_id, status, created_at) with the
 *   index present, 3.00 ms without it. Pure write overhead.
 * - activity_log (created_at), for the tenant-agnostic activitylog:clean
 *   predicate. The intuition is that (tenant_id, created_at) cannot serve a
 *   query with no tenant_id term — true of a btree range scan, but Postgres
 *   simply bitmap-scans the composite instead. Measured on 200k rows at
 *   realistic purge selectivity: 0.85 ms / 116 buffers via the existing
 *   composite, versus 1.07 ms / 96 buffers with a dedicated index. Not worth
 *   another index to maintain on the highest-write table in the schema.
 * - activity_log (tenant_id, subject_type, subject_id). The existing
 *   (subject_type, subject_id) already resolves to one subject, which is as
 *   selective as it gets; a tenant_id prefix would only make the index wider.
 * - notifications expression index on (data->>'tenant_id'). The bell query
 *   filters notifiable_id first, which bounds the scan to one user's rows.
 *   Worth revisiting only if a single user's notification count grows large.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table): void {
            // FetchDashboardTrendQuery: answered_at IS NOT NULL AND >= cutoff,
            // tenant-scoped. answered_at has been unindexed since it was added,
            // so the eight-week look-back scanned the tenant's whole partition.
            // Measured on 150k rows: 12.1 ms versus 22.8 ms for the seq scan,
            // and the gap widens as answers accumulate past the window.
            $table->index(['tenant_id', 'answered_at']);
        });

        Schema::table('pipelines', function (Blueprint $table): void {
            // RetryDuePipelinesCommand, every five minutes per tenant:
            // status = failed AND next_retry_at <= now, ordered by next_retry_at.
            // Column order matches that predicate so the sort is satisfied too.
            // Measured on 150k rows: 0.076 ms / 56 buffers with this index,
            // versus 7.5 ms / 2450 buffers without, where the planner falls back
            // to a bitmap scan over every failed run plus a top-N heapsort.
            $table->index(['tenant_id', 'status', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::table('pipelines', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'status', 'next_retry_at']);
        });

        Schema::table('document_requests', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'answered_at']);
        });
    }
};
