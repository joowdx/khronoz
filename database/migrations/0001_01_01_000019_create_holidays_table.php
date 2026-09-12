<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform holidays apply globally; `type` describes the pay rate, not scope.
     */
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->date('date');
            // NOT NULL makes this unique-key component effective.
            $table->string('name');
            $table->string('type');
            // The reference remains nullable until the document arrives.
            $table->string('reference')->nullable();
            // Derivation applies this declaration prospectively.
            $table->timestamp('declared_at');
            $table->timestamps();

            $table->unique(['id', 'agency_id']);

            // Coincident holidays require separate named rows on one date.
            $table->unique(['agency_id', 'date', 'name']);
        });

        DB::statement("ALTER TABLE holidays ADD CONSTRAINT holidays_type_valid CHECK (type IN ('regular', 'special', 'working', 'local'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
