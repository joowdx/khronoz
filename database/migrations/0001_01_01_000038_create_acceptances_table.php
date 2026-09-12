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
        Schema::create('acceptances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('user_id');
            $table->string('document');
            $table->string('version');
            $table->string('content_hash', 64);
            $table->timestamp('accepted_at');
            $table->unique(['user_id', 'document', 'version']);
            $table->foreign(['user_id', 'agency_id'])->references(['id', 'agency_id'])->on('users')
                ->cascadeOnDelete()->restrictOnUpdate();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE acceptances
                ADD CONSTRAINT acceptances_document_valid CHECK (document IN ('privacy-policy', 'user-agreement')),
                ADD CONSTRAINT acceptances_version_valid CHECK (version ~ '^[a-z0-9][a-z0-9.-]*$'),
                ADD CONSTRAINT acceptances_hash_valid CHECK (content_hash ~ '^[a-f0-9]{64}$');
        SQL);

        AppRoleGrants::restrict();
    }

    public function down(): void
    {
        Schema::dropIfExists('acceptances');
    }
};
