<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client's exercise of their access or erasure rights, logged before it is
 * acted on.
 *
 * Two steps rather than one because the regulation allows a month to respond and
 * erasure cannot be undone: a firm needs somewhere to record that a request
 * arrived, when it is due, and who eventually dealt with it — including when the
 * answer was "no, and here is why".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_subject_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            $table->string('type');
            $table->string('status');

            $table->timestamp('requested_at');
            // requested_at + retention.data_subject_response_days.
            $table->timestamp('due_at');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('refused_at')->nullable();
            $table->foreignId('refused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('refusal_reason', 500)->nullable();

            // Export artefact. Expires so a copy of everything about one person
            // does not sit on the disk indefinitely.
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_subject_requests');
    }
};
