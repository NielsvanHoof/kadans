<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audio_recordings', function (Blueprint $table) {
            $table->json('segments')->nullable()->after('transcript');
        });
    }

    public function down(): void
    {
        Schema::table('audio_recordings', function (Blueprint $table) {
            $table->dropColumn('segments');
        });
    }
};
