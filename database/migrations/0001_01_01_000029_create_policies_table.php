<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('workgroup_id')->nullable();
            $table->ulid('employee_id')->nullable();
            $table->string('template')->nullable();
            $table->jsonb('roles')->nullable();
            $table->string('supervisor')->nullable();
            $table->string('head_kind')->nullable();
            $table->timestamps();
            $table->unique(['id', 'agency_id']);
            foreach (['workgroup_id' => 'workgroups', 'employee_id' => 'employees'] as $column => $parent) {
                $table->foreign([$column, 'agency_id'])->references(['id', 'agency_id'])->on($parent)->restrictOnDelete()->restrictOnUpdate();
            }
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE policies
                ADD CONSTRAINT policies_template_valid CHECK (template IS NULL OR template IN ('form48', 'plain')),
                ADD CONSTRAINT policies_supervisor_valid CHECK (supervisor IS NULL OR supervisor IN ('operative', 'substantive')),
                ADD CONSTRAINT policies_roles_valid CHECK (
                    roles IS NULL OR CASE WHEN jsonb_typeof(roles) = 'array' THEN
                        jsonb_array_length(roles) BETWEEN 1 AND 4
                        AND roles <@ '["employee", "supervisor", "head", "timekeeper"]'::jsonb
                        AND jsonb_array_length(roles) =
                            CASE WHEN roles ? 'employee' THEN 1 ELSE 0 END
                            + CASE WHEN roles ? 'supervisor' THEN 1 ELSE 0 END
                            + CASE WHEN roles ? 'head' THEN 1 ELSE 0 END
                            + CASE WHEN roles ? 'timekeeper' THEN 1 ELSE 0 END
                    ELSE false END
                ),
                ADD CONSTRAINT policies_one_scope CHECK (employee_id IS NULL OR workgroup_id IS NULL);
            CREATE UNIQUE INDEX policies_agency_scope ON policies (agency_id) WHERE employee_id IS NULL AND workgroup_id IS NULL;
            CREATE UNIQUE INDEX policies_workgroup_scope ON policies (workgroup_id) WHERE workgroup_id IS NOT NULL;
            CREATE UNIQUE INDEX policies_employee_scope ON policies (employee_id) WHERE employee_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};
