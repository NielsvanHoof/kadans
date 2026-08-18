<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External Amazon Transcribe job id for async EventBridge/SQS completion lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audio_recordings', function (Blueprint $table): void {
            $table->string('transcribe_job_id')->nullable()->after('transcription_status');
            $table->index('transcribe_job_id');
        });
    }

    public function down(): void
    {
        Schema::table('audio_recordings', function (Blueprint $table): void {
            $table->dropIndex(['transcribe_job_id']);
            $table->dropColumn('transcribe_job_id');
        });
    }
};
