<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('user_id');
            $table->string('name', 100);
            $table->string('credential_id', 1400)->unique();
            $table->jsonb('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['id', 'agency_id']);
            $table->foreign(['user_id', 'agency_id'])->references(['id', 'agency_id'])->on('users')->cascadeOnDelete()->restrictOnUpdate();
            $table->index(['user_id', 'agency_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
