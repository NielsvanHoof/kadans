<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a fulfilment job took the request, so a second one can tell.
 *
 * The export spends up to ten minutes zipping and streaming to S3, which is far
 * too long to hold the `lockForUpdate` the erasure uses — that one is safe only
 * because the whole erasure fits inside a single transaction. A timestamp
 * claimed by a conditional UPDATE gives the same mutual exclusion without
 * pinning a connection for the duration.
 *
 * Deliberately not a new status: `pending -> completed|refused` is the recorded
 * flow (DataSubjectRequestTransitions), both closed states are terminal, and an
 * in-flight export is still a pending request as far as the operator, the
 * overdue calculation and the compliance list are concerned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_subject_requests', function (Blueprint $table): void {
            $table->timestamp('fulfilment_started_at')->nullable()->after('due_at');
        });
    }

    public function down(): void
    {
        Schema::table('data_subject_requests', function (Blueprint $table): void {
            $table->dropColumn('fulfilment_started_at');
        });
    }
};
