<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            $table->unsignedSmallInteger('period_year')->nullable()->after('reference');
            $table->string('period_label')->nullable()->after('period_year');

            // Self-referencing: the dossier this one was rolled over from.
            // nullOnDelete rather than cascade — deleting last year's dossier
            // must not take this year's work with it.
            $table->foreignId('rolled_from_dossier_id')
                ->nullable()
                ->after('period_label')
                ->constrained('dossiers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rolled_from_dossier_id');
            $table->dropColumn(['period_year', 'period_label']);
        });
    }
};
