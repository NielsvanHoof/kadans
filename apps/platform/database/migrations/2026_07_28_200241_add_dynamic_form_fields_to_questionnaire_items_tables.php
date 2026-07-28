<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questionnaire_template_items', function (Blueprint $table) {
            $table->string('field_key', 26)->nullable()->unique();
            $table->json('configuration')->nullable();
        });

        Schema::table('document_requests', function (Blueprint $table) {
            $table->string('field_key', 26)->nullable()->unique();
            $table->json('configuration')->nullable();
            $table->json('answer_option_ids')->nullable();
        });

        $this->backfillFieldKeys('questionnaire_template_items');
        $this->backfillFieldKeys('document_requests');
    }

    public function down(): void
    {
        Schema::table('questionnaire_template_items', function (Blueprint $table) {
            $table->dropUnique(['field_key']);
            $table->dropColumn(['field_key', 'configuration']);
        });

        Schema::table('document_requests', function (Blueprint $table) {
            $table->dropUnique(['field_key']);
            $table->dropColumn([
                'field_key',
                'configuration',
                'answer_option_ids',
            ]);
        });
    }

    private function backfillFieldKeys(string $table): void
    {
        DB::table($table)
            ->whereNull('field_key')
            ->orderBy('id')
            ->eachById(function (object $item) use ($table): void {
                DB::table($table)
                    ->where('id', $item->id)
                    ->update(['field_key' => (string) Str::ulid()]);
            });
    }
};
