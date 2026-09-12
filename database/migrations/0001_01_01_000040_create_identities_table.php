<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('user_id');
            $table->string('provider', 16);
            $table->string('subject');
            $table->string('email')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['id', 'agency_id']);
            $table->unique(['provider', 'subject']);
            $table->unique(['user_id', 'provider']);
            $table->foreign(['user_id', 'agency_id'])->references(['id', 'agency_id'])->on('users')->cascadeOnDelete()->restrictOnUpdate();
        });
        DB::statement("ALTER TABLE identities ADD CONSTRAINT identities_provider_valid CHECK (provider IN ('google', 'apple'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('identities');
    }
};
