<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One employee-month, with a lock on it and nothing else
     * (docs/design/06-attendance.md Ledger rules 1–3). Totals and occurrence
     * counts are derived from its workdays, which arrive in a later migration.
     *
     * No `deployment_id` (decision 30): visibility is a predicate over
     * overlapping deployment ranges, and a single FK would hand a split month
     * to exactly one workgroup. The freeze on those ranges is
     * `deployments_frozen_month`, attached here because it queries `ledgers`,
     * which did not exist at 000011.
     *
     * No `agency_not_platform` trigger: a ledger needs an employee, and
     * `employees` refuses the platform agency already.
     */
    public function up(): void
    {
        Schema::create('ledgers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('employee_id');
            $table->date('month');
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'agency_id']);
            $table->unique(['employee_id', 'month']);
            $table->unique(['id', 'employee_id', 'month']);

            $table->foreign(['employee_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('employees')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE ledgers ADD CONSTRAINT ledgers_month_is_first_of_month CHECK (month = make_date(extract(year from month)::int, extract(month from month)::int, 1))');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledgers_lock_complete
                BEFORE INSERT OR UPDATE OF locked_at ON ledgers
                FOR EACH ROW WHEN (NEW.locked_at IS NOT NULL)
                EXECUTE FUNCTION ledgers_lock_complete();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledgers_unlock_clean
                BEFORE UPDATE OF locked_at ON ledgers
                FOR EACH ROW WHEN (NEW.locked_at IS NULL)
                EXECUTE FUNCTION ledgers_unlock_clean();
        SQL);

        // Attached here, not in 000011: the function queries `ledgers`.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER deployments_frozen_month
                BEFORE INSERT OR UPDATE OR DELETE ON deployments
                FOR EACH ROW EXECUTE FUNCTION deployments_frozen_month();
        SQL);
    }

    /**
     * The ledgers triggers go with the table. `deployments_frozen_month` is
     * on `deployments`, so it is dropped explicitly; the function belongs to
     * 0001_01_01_000028_prepare_attendance and is dropped only there.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS deployments_frozen_month ON deployments');

        Schema::dropIfExists('ledgers');
    }
};
