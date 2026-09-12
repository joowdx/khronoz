<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('document_id');
            $table->string('store');
            $table->text('key');
            $table->timestamp('verified_at')->nullable();
            $table->boolean('primary')->default(false);
            $table->timestamp('retired_at')->nullable();
            $table->foreign(['document_id', 'agency_id'])->references(['id', 'agency_id'])->on('documents')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['store', 'key']);
            $table->timestamps();
            $table->unique(['id', 'agency_id']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE locations ADD CONSTRAINT locations_store_present CHECK (length(store) > 0), ADD CONSTRAINT locations_key_present CHECK (length(key) > 0), ADD CONSTRAINT locations_primary_verified CHECK (NOT "primary" OR (verified_at IS NOT NULL AND retired_at IS NULL));
            CREATE UNIQUE INDEX locations_one_primary ON locations (document_id) WHERE "primary" AND retired_at IS NULL;
        SQL);
        AppRoleGrants::restrict();
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
