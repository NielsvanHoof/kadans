<?php

declare(strict_types=1);

use App\Tenancy\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('ai_document_overview_enabled')->default(true);
            $table->boolean('ai_checklist_draft_enabled')->default(true);
            $table->boolean('ai_reminder_draft_enabled')->default(true);
            $table->timestamps();
        });

        $now = now();

        Tenant::query()->pluck('id')->each(function (int $tenantId) use ($now): void {
            DB::table('tenant_settings')->insert([
                'tenant_id' => $tenantId,
                'ai_document_overview_enabled' => true,
                'ai_checklist_draft_enabled' => true,
                'ai_reminder_draft_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
