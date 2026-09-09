<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The paired FK `users.employee_id` has been waiting for since Milestone 1
     * (0001_01_01_000000_create_users_table: "paired FK to employees arrives
     * with Milestone 2"). It is its own migration because it cannot be
     * declared with `users`, which is created long before `employees` exists.
     *
     * Paired, so a user can only be linked to an employee of the user's own
     * agency. Two things follow for free, both from FKs using MATCH SIMPLE:
     * a staff or platform user with `employee_id` null still inserts, because
     * the check is skipped when any referencing column is null; and a
     * superuser (`agency_id` = the platform agency, which has no employees by
     * trigger) can never be linked to a person at all.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign(['employee_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('employees')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_id', 'agency_id']);
        });
    }
};
