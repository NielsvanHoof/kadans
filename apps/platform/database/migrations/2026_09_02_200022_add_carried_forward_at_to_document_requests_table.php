<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks an answer that was carried over from the previous period rather
     * than given for this one.
     *
     * Deliberately not a new DocumentRequestStatus case: rolled-over requests
     * stay Pending, so isAwaitingClient(), the dashboard follow-up queries and
     * portal progress keep working untouched. This timestamp is what lets the
     * portal render the value as a suggestion to confirm (TASK-52) instead of
     * an answer already given.
     */
    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->timestamp('carried_forward_at')->nullable()->after('answered_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->dropColumn('carried_forward_at');
        });
    }
};
