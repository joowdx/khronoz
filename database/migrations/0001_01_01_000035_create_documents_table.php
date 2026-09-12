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
        Schema::create('documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->string('name');
            $table->string('mime');
            $table->unsignedBigInteger('bytes');
            $table->string('algorithm');
            $table->string('digest');
            $table->timestamps();
            $table->unique(['id', 'agency_id']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE documents ADD CONSTRAINT documents_bytes_valid CHECK (bytes >= 0), ADD CONSTRAINT documents_digest_valid CHECK (length(algorithm) > 0 AND length(digest) > 0 AND (algorithm <> 'sha256' OR digest ~ '^[a-f0-9]{64}$'));
        SQL);
        AppRoleGrants::restrict();
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
