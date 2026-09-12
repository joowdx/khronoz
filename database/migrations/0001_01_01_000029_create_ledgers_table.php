<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deployment visibility is range-based, so a split month cannot have one deployment foreign key.
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

        // Attach these triggers after ledgers exist because their functions query it.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER deployments_frozen_month
                BEFORE INSERT OR UPDATE OR DELETE ON deployments
                FOR EACH ROW EXECUTE FUNCTION deployments_frozen_month();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER exemptions_frozen_month
                BEFORE INSERT OR UPDATE OR DELETE ON exemptions
                FOR EACH ROW EXECUTE FUNCTION exemptions_frozen_month();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER overtimes_frozen_month
                BEFORE INSERT OR UPDATE OR DELETE ON overtimes
                FOR EACH ROW EXECUTE FUNCTION overtimes_frozen_month();
        SQL);
    }

    /**
     * The ledgers triggers go with the table. The three frozen-month
     * triggers live on other tables, so they are dropped explicitly; their
     * functions belong to 0001_01_01_000028_prepare_attendance and are
     * dropped only there.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS deployments_frozen_month ON deployments');
        DB::unprepared('DROP TRIGGER IF EXISTS exemptions_frozen_month ON exemptions');
        DB::unprepared('DROP TRIGGER IF EXISTS overtimes_frozen_month ON overtimes');

        Schema::dropIfExists('ledgers');
    }
};
