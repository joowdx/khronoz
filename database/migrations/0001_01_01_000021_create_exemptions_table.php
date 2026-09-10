<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A period an employee is excused from (docs/design/05-calendar.md rules 4
     * and 7): leave, official business, travel, CTO, a pass slip, a personal
     * locator slip, an emergency.
     *
     * Times, not `am / pm / full`, so a Friday prayer 10:00–14:00 that crosses
     * noon, a two-hour pass slip and a 40-minute lactation break are all one
     * shape. Null `starts` is the whole day.
     *
     * `date`..`until` makes the row a **date range** rather than a single day
     * (decisions 37 and 38), and that is what continuous statutory leave
     * needs: RA
     * 11210 gives 105 continuous days for a live birth, 60 for a miscarriage,
     * +15 for a qualified solo parent and +30 unpaid. Continuous means it
     * spans non-workdays, so it cannot be a run of per-day rows without
     * inventing rows for rest days — and it is one authority in one order, not
     * 105 decisions. Rehabilitation leave, study leave, terminal leave, RA
     * 9710's two months and paternity's seven days are the same shape, so the
     * range belongs to the existing `leave` type rather than to a new table.
     *
     * `UNIQUE (id, employee_id)` exists for a table that does not exist yet:
     * `workdays.exemption_id` pairs against it in Milestone 6, proving the
     * stamped exemption is that employee's own. Same device, and same reason,
     * as `deployments`' own pair.
     */
    public function up(): void
    {
        Schema::create('exemptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('employee_id');
            // The first day, and the only day unless `until` is set.
            $table->date('date');
            // The last day, **inclusive**. NOT NULL, and a one-day exemption
            // carries `until = date` (decision 38).
            //
            // An earlier draft made this nullable with null meaning one day,
            // which was a trap rather than a convenience: `daterange(date,
            // until, '[]')` with a null upper bound is **unbounded above**, so
            // any future query or exclusion constraint built the way the rest
            // of this schema builds ranges would have read a two-hour pass
            // slip as excusing every day thereafter. Documentation cannot
            // reach SQL nobody has written yet; NOT NULL can. The cost is one
            // normalisation in the writer, and the failure mode moves from
            // silently over-excusing to a loud 23502.
            $table->date('until');
            $table->string('type');
            // The excused window, or null for whole days. A nullable pair,
            // held whole by exemptions_hours_paired.
            $table->time('starts')->nullable();
            $table->time('ends')->nullable();
            // The order, form or certificate this came from. Nullable for the
            // reason holidays.reference is.
            $table->string('reference')->nullable();
            $table->text('remarks')->nullable();
            // Who entered it. Single-column, for the reason
            // suspensions.user_id is: a platform superuser who entered the
            // agency may do the data entry, and pairing would refuse them.
            $table->ulid('user_id');
            // Timekeeper-entered in v1 with this set on entry (rule 5), so
            // NOT NULL: filing and approval workflows are phase 2, and until
            // they exist an unapproved exemption is a state the deriver has no
            // rule for. Relaxing this later is a safe migration; shipping a
            // null nobody can interpret is not.
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->unique(['id', 'agency_id']);

            // The target workdays.exemption_id pairs against in Milestone 6.
            // Trivially satisfied — `id` is already unique — and nothing
            // references it yet, exactly as `deployments`' pair predated its
            // own use.
            $table->unique(['id', 'employee_id']);

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

        DB::statement(<<<'SQL'
            ALTER TABLE exemptions ADD CONSTRAINT exemptions_type_valid CHECK (
                type IN ('leave', 'business', 'travel', 'cto', 'pass', 'personal', 'emergency')
            )
        SQL);

        // `>=`, so a one-day exemption is `until = date`. There is still
        // exactly one spelling of a single day — it is just the obvious one
        // now, rather than a null that means the opposite of every other null
        // in the schema.
        DB::statement('ALTER TABLE exemptions ADD CONSTRAINT exemptions_span_ordered CHECK (until >= date)');

        // The nullable time pair, held whole for the reason
        // suspensions_hours_paired is: "excused from 10:00 until nothing"
        // leaves the deriver guessing an end.
        DB::statement('ALTER TABLE exemptions ADD CONSTRAINT exemptions_hours_paired CHECK ((starts IS NULL) = (ends IS NULL))');
        DB::statement('ALTER TABLE exemptions ADD CONSTRAINT exemptions_hours_ordered CHECK (starts IS NULL OR ends > starts)');

        // A multi-day exemption is whole days. A 10:00–14:00 window repeated
        // across 105 days of maternity leave is not something anyone means,
        // and a row saying it would make the deriver excuse four hours a day
        // of a continuous leave — under-excusing by an entire statutory
        // entitlement. The two features are each other's negation, so this
        // says so once rather than leaving every reader to decide.
        DB::statement('ALTER TABLE exemptions ADD CONSTRAINT exemptions_span_is_whole_days CHECK (until = date OR starts IS NULL)');


        // The recording user must belong to this agency or be a platform
        // user; no FK can say "or", so this trigger does. The function and
        // its reasoning are in 0001_01_01_000018_prepare_calendar.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER actor_of_agency
                BEFORE INSERT OR UPDATE OF user_id, agency_id ON exemptions
                FOR EACH ROW EXECUTE FUNCTION actor_of_agency();
        SQL);

        // Deliberately absent: any exclusion constraint over
        // (employee_id, the range). Two exemptions may share a day — a
        // two-hour pass in the morning and a CTO in the afternoon — and a
        // whole-day one may sit inside a longer leave after a correction.
        // Milestone 6 stamps one `workdays.exemption_id` per day and picks by
        // precedence; that is a deriver rule, not a schema one, and it is
        // recorded as an open item in 06-attendance.md so M6 does not have to
        // rediscover that the database names no winner.
        //
        // Now that both bounds are NOT NULL, such a constraint would at least
        // be *expressible* — EXCLUDE USING gist (employee_id WITH =,
        // daterange(date, until, '[]') WITH &&) — which it was not while
        // `until` was nullable. It is still not wanted.
    }

    /**
     * The trigger goes with the table; `actor_of_agency()` belongs to
     * 0001_01_01_000018_prepare_calendar and is dropped only there.
     */
    public function down(): void
    {
        Schema::dropIfExists('exemptions');
    }
};
