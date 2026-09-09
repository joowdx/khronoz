<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('employee_id')->nullable()->unique(); // paired FK to employees arrives with Milestone 2
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->jsonb('permissions')->default(DB::raw("'[]'::jsonb"));
            $table->timestamp('invited_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->unique(['id', 'agency_id']);
        });

        // A plain unique() on email would still block exact duplicates but let
        // 'Ana@x.test' and 'ana@x.test' coexist; login and invites must treat
        // those as the same address, so the index is keyed on lower(email).
        DB::statement('CREATE UNIQUE INDEX users_email ON users (lower(email))');

        // OR REPLACE, not a bare CREATE: migrate:fresh drops users (and with it,
        // this constraint) via db:wipe, but db:wipe only drops tables, views and
        // (on Postgres, opt-in) types — never functions — so a plain CREATE
        // FUNCTION would collide with itself on the second migrate:fresh against
        // the same database, which is exactly what every test run after the
        // first does.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION permissions_valid(permissions jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
                SELECT jsonb_typeof(permissions) = 'array'
                   AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(permissions) e WHERE jsonb_typeof(e) <> 'string')
                   AND jsonb_array_length(permissions) = (SELECT count(DISTINCT e) FROM jsonb_array_elements_text(permissions) e);
            $$;
            ALTER TABLE users ADD CONSTRAINT users_permissions_valid CHECK (permissions_valid(permissions));
        SQL);

        Schema::create('resets', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUlid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        DB::statement('DROP FUNCTION IF EXISTS permissions_valid(jsonb)');
        Schema::dropIfExists('resets');
        Schema::dropIfExists('sessions');
    }
};
