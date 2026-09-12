<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Timestamp bounds keep overnight authority in one row.
     */
    public function up(): void
    {
        Schema::create('overtimes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('employee_id');
            $table->timestamp('starts');
            $table->timestamp('ends');
            // STORED permits indexing and foreign keys; overtime belongs to its start date.
            $table->date('date')->storedAs('starts::date');
            // Why the work was authorised. NOT NULL for the reason
            // suspensions.reason is: it is the justification and it prints.
            $table->string('purpose');
            $table->string('mode');
            // The office order. Nullable, as elsewhere.
            $table->string('reference')->nullable();
            // Who approved it. Single-column, for the reason
            // suspensions.user_id is.
            $table->ulid('user_id');
            $table->timestamps();

            $table->unique(['id', 'agency_id']);

            $table->foreign(['employee_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('employees')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE overtimes ADD CONSTRAINT overtimes_dates_ordered CHECK (ends > starts)');
        DB::statement("ALTER TABLE overtimes ADD CONSTRAINT overtimes_mode_valid CHECK (mode IN ('pay', 'cto'))");

        // Timestamp ranges use [) so adjoining authority windows do not overlap.
        // The immediate exclusion remains catchable in SAVEPOINT tests.
        DB::statement('ALTER TABLE overtimes ADD CONSTRAINT overtimes_no_overlap EXCLUDE USING gist (employee_id WITH =, tsrange(starts, ends) WITH &&)');

        // The recording user must belong to this agency or be a platform
        // user; no FK can say "or", so this trigger does. The function and
        // its reasoning are in 0001_01_01_000018_prepare_calendar.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER actor_of_agency
                BEFORE INSERT OR UPDATE OF user_id, agency_id ON overtimes
                FOR EACH ROW EXECUTE FUNCTION actor_of_agency();
        SQL);
    }

    /**
     * The trigger goes with the table; `actor_of_agency()` belongs to
     * 0001_01_01_000018_prepare_calendar and is dropped only there.
     */
    public function down(): void
    {
        Schema::dropIfExists('overtimes');
    }
};
