<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One employee-day: the DTR line, with the shift it was computed against
     * frozen into it (docs/design/06-attendance.md Workday rules 1–3).
     *
     * Nothing computes yet — this table is the receipt the pipeline will
     * write. `shift` is the json snapshot; `shift_id` is only provenance.
     *
     * There is a `created_at` and deliberately **no `updated_at`**: a workday
     * is fully derived and rewritten wholesale by each recompute, so "when
     * was this row last written" and "when was it last computed" are one
     * fact. `computed_at` is that fact. A second column holding the same
     * instant is the mixed-concern defect docs/reference/clockwork-audit.md
     * records.
     */
    public function up(): void
    {
        Schema::create('workdays', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('ledger_id');
            $table->ulid('employee_id');
            $table->date('date');
            // Generated from `date`, so it cannot disagree with the day it
            // summarises, and STORED rather than left to the default:
            // Postgres 18 defaults a generated column to VIRTUAL, and a
            // virtual column can be neither indexed nor referenced by a
            // foreign key — `month` is half of the three-column ledger FK.
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
    }

    public function down(): void
    {
        Schema::dropIfExists('workdays');
    }
};
