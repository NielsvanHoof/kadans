<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user OAuth connections (Microsoft first). User-owned, not tenant-owned —
 * one person can belong to several firms and still have one Microsoft link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Enum-like string — cast to OAuthProvider in the model.
            $table->string('provider');
            $table->string('provider_user_id');
            $table->string('email')->nullable();
            // Encrypted at rest via Eloquent encrypted cast.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_connections');
    }
};
