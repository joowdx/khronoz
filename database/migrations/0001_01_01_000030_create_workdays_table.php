<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workdays retain a shift snapshot; `computed_at` is their sole update timestamp.
     */
    public function up(): void
    {
        Schema::create('workdays', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('ledger_id');
            $table->ulid('employee_id');
            $table->date('date');
            // STORED supports the ledger foreign key and cannot disagree with `date`.
            $table->date('month')->storedAs('make_date(extract(year from date)::int, extract(month from date)::int, 1)');
            $table->ulid('shift_id')->nullable();
            $table->jsonb('shift')->nullable();
            $table->ulid('exemption_id')->nullable();
            $table->string('status');
            $table->string('premium')->nullable();
            $table->smallInteger('worked')->default(0);
            $table->smallInteger('credited')->default(0);
            $table->smallInteger('tardy')->default(0);
            $table->smallInteger('undertime')->default(0);
            $table->smallInteger('excess')->default(0);
            $table->smallInteger('night')->default(0);
            $table->smallInteger('night_excess')->default(0);
            $table->timestamp('computed_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['id', 'agency_id']);
            $table->unique(['employee_id', 'date']);
            // Target for the punch FK: a punch is provably that employee's
            // own workday.
            $table->unique(['id', 'employee_id']);

            // No agency_id on this pair: (ledger_id, employee_id, month)
            // already proves right-employee and right-month, which is
            // stronger, and employees is reached through the ledger.
            $table->foreign(['ledger_id', 'employee_id', 'month'])
                ->references(['id', 'employee_id', 'month'])
                ->on('ledgers')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign(['shift_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('shifts')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign(['exemption_id', 'employee_id'])
                ->references(['id', 'employee_id'])
                ->on('exemptions')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE workdays ADD CONSTRAINT workdays_status_valid CHECK (status IN ('present', 'absent', 'off', 'holiday', 'exempt', 'suspended', 'remote'))");
        DB::statement("ALTER TABLE workdays ADD CONSTRAINT workdays_premium_valid CHECK (premium IS NULL OR premium IN ('rest', 'special', 'regular'))");
        DB::statement('ALTER TABLE workdays ADD CONSTRAINT workdays_minutes_not_negative CHECK (worked >= 0 AND credited >= 0 AND tardy >= 0 AND undertime >= 0 AND excess >= 0 AND night >= 0 AND night_excess >= 0)');
        DB::statement('ALTER TABLE workdays ADD CONSTRAINT workdays_credited_needs_premium CHECK (credited = 0 OR premium IS NOT NULL)');
        DB::statement("ALTER TABLE workdays ADD CONSTRAINT workdays_shift_is_object CHECK (shift IS NULL OR jsonb_typeof(shift) = 'object')");

        // Locked months refuse workday writes at the database boundary.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER workdays_ledger_open
                BEFORE INSERT OR UPDATE OR DELETE ON workdays
                FOR EACH ROW EXECUTE FUNCTION workdays_ledger_open();
        SQL);
    }

    /**
     * The trigger goes with the table; `workdays_ledger_open()` belongs to
     * 0001_01_01_000028_prepare_attendance and is dropped only there.
     */
    public function down(): void
    {
        Schema::dropIfExists('workdays');
    }
};
