<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploaded_documents', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'document_request_id']);
            $table->index('document_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('uploaded_documents', function (Blueprint $table) {
            $table->dropIndex(['document_request_id']);
            $table->unique(['tenant_id', 'document_request_id']);
        });
    }
};
