<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An authority to work beyond the shift (docs/design/05-calendar.md rules
     * 4 and 6). Without one, work outside the shift is recorded as excess and
     * is never compensable.
     *
     * `starts` and `ends` are **timestamps**, not a date plus two times, so an
     * overnight authority — 22:00 to 02:00 — is one row rather than two
     * halves nothing joins. That is the one place this table diverges from
     * `suspensions` and `exemptions`, which are declared for a date and a
     * clock time.
     *
     * The JC 2 s. 2015 gates are **not here and are not settings**: on-time
     * arrival, at least two hours beyond the shift, at most twelve on a rest
     * day or holiday. They gate overtime *with pay* and are regime constants
     * (decision 34, which renamed that row "overtime gates and limits"
     * precisely because two ideas were being read as one). What makes a
     * minute overtime at all is the resolved shift's own prescribed duration,
     * which lives on `shifts`. Nothing evaluates any of it until Milestone 6.
     */
    public function up(): void
    {
        Schema::create('overtimes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('employee_id');
            $table->timestamp('starts');
            $table->timestamp('ends');
            // Generated, so it cannot disagree with `starts`, and STORED
            // rather than left to the default: Postgres 18 defaults a
            // generated column to VIRTUAL, and a virtual column can be
            // neither indexed nor referenced by a foreign key — which is the
            // whole reason this column exists.
            //
            // `starts::date` and not `ends::date`: an overnight authority
            // belongs to the day it began, so 22:00 on the 1st through 02:00
            // on the 2nd is the 1st's authority. That matches how the shift
            // it extends is attributed, and it is the same question
            // 06-attendance.md carries as its open cross-midnight item.
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

        // One authority at a time per employee. Note **two** deliberate
        // departures from every other range in this schema, and do not
        // "fix" either.
        //
        // `tsrange` and not `daterange`, because these are instants: an
        // authority for 22:00–02:00 and another for the following evening are
        // two rows on one calendar day and must not collide.
        //
        // And the **default `[)` bound**, where every daterange here is '[]'.
        // The difference is visible at exactly one point: two authorities
        // meeting at an instant — 18:00–20:00 and 20:00–22:00 — do not
        // conflict, which is right, because the first has ended when the
        // second begins. Two date ranges sharing a day *do* conflict, because
        // the employee really is in both on that day. Same operator, opposite
        // answer, because a day is an interval and an instant is not.
        //
        // Not deferrable, for the reason deployments' exclusions are not:
        // assertDatabaseRefuses() runs each statement in a SAVEPOINT, and
        // releasing one does not run deferred checks, so a deferred
        // constraint would be uncatchable and its test vacuously green.
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
