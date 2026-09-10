<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Work suspended for a workgroup, or for the whole agency, on a date
     * (docs/design/05-calendar.md rule 2 and rule 3). A typhoon, a flood, a
     * power outage, a local emergency.
     *
     * `workgroup_id` **null means agency-wide**, and a set one cascades to
     * that workgroup's descendants. MATCH SIMPLE makes the first half free:
     * a null in the referencing pair skips the paired FK entirely, which is
     * why "agency-wide" needs no sentinel row and no second table. `agency_id`
     * still carries its own FK, so an agency-wide suspension is still proven
     * to belong to a real agency.
     *
     * Whose day it excuses is **not** "every employee deployed under the
     * workgroup" — that is the trap 05-calendar.md rule 3 exists to name.
     * During a detail an employee has two deployment rows covering the date,
     * and a closure declared on the mother office must not excuse a day the
     * person actually worked in the receiving one. The rule reads the
     * **operative** row: the reassignment if one covers the date, otherwise
     * the substantive placement (decision 31). That resolution is application
     * logic — it walks a workgroup subtree — and no constraint here attempts
     * it.
     */
    public function up(): void
    {
        Schema::create('suspensions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            // Null: agency-wide. Set: this workgroup and its descendants.
            $table->ulid('workgroup_id')->nullable();
            $table->date('date');
            // The suspended window, or null for the whole day. A nullable
            // *pair*, held whole by suspensions_hours_paired below: a
            // half-set pair is the bug that constraint exists to stop.
            //
            // `time` and not timestamp, because a suspension is declared for
            // a date and a clock time — "work is suspended from 12 noon". A
            // night shift crossing midnight spans two dates and therefore two
            // suspensions, which is right rather than a limitation: the second
            // date is a separate declaration in the memo too.
            $table->time('starts')->nullable();
            $table->time('ends')->nullable();
            // Why work was suspended. NOT NULL: it is the operative
            // justification, it prints on the DTR, and a suspension without
            // one is not a record of anything.
            $table->string('reason');
            // The memo or announcement number. Nullable for the reason
            // holidays.reference is: an office acts on the announcement before
            // the paper reaches them.
            $table->string('reference')->nullable();
            // Who declared it. A **single**-column FK, deliberately, where
            // `attestations.user_id` is paired against (id, agency_id): a
            // signature must come from inside the agency, but a declaration
            // may be entered by a platform superuser who has entered the
            // agency, and whose own agency_id is the platform row. Pairing
            // here would refuse exactly the person doing the data entry.
            $table->ulid('user_id');
            // When the declaration took effect, not when it was typed.
            // Prospective (Res. 2600838 §2.5): an employee with no punch
            // before this moment is absent for the part of the shift before
            // `starts` (Omnibus Rules on Leave §32), so the deriver compares
            // punches against it. Nothing computes a workday until M6.
            $table->timestamp('declared_at');
            $table->timestamps();

            $table->unique(['id', 'agency_id']);

            $table->foreign(['workgroup_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('workgroups')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        // A nullable pair, held whole. Null means the whole day is suspended;
        // a half-set pair would mean "suspended from noon until nothing", and
        // the deriver would have to guess. `=` between two booleans is the
        // whole rule and needs no COALESCE: both null is true = true, both
        // set is false = false, one of each is the only refusal.
        DB::statement('ALTER TABLE suspensions ADD CONSTRAINT suspensions_hours_paired CHECK ((starts IS NULL) = (ends IS NULL))');

        // Strictly greater, unlike every date range in this schema, which
        // uses `>=` on inclusive '[]' bounds. A date range of one day is a
        // real thing; a time window of zero length suspends nothing, so the
        // two cases are genuinely different and the operators differ on
        // purpose.
        DB::statement('ALTER TABLE suspensions ADD CONSTRAINT suspensions_hours_ordered CHECK (starts IS NULL OR ends > starts)');


        // The recording user must belong to this agency or be a platform
        // user; no FK can say "or", so this trigger does. The function and
        // its reasoning are in 0001_01_01_000018_prepare_calendar.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER actor_of_agency
                BEFORE INSERT OR UPDATE OF user_id, agency_id ON suspensions
                FOR EACH ROW EXECUTE FUNCTION actor_of_agency();
        SQL);

        // Deliberately absent: any uniqueness or exclusion constraint over
        // (agency_id, workgroup_id, date). Two suspensions on one date are
        // ordinary — a morning window and an afternoon one, or an agency-wide
        // declaration that a division extends for its own reason — so
        // overlap is permitted and the deriver takes the union. That does
        // leave the same memo enterable twice; there is no natural key that
        // would catch it (`reason` is free text) and refusing legitimate
        // second declarations to prevent a typo is the worse trade.
        //
        // Worth knowing before anyone adds one anyway: such a constraint
        // would not even work. `workgroup_id` is null on every agency-wide
        // row and a UNIQUE index treats nulls as distinct, so it would
        // refuse the legitimate workgroup-scoped pairs while permitting
        // unlimited duplicate agency-wide ones — the same NULLS DISTINCT
        // fact that makes `holidays.name NOT NULL` load-bearing, biting in
        // the opposite direction. Found by mutation testing, which is why
        // SuspensionTest scopes that test to a workgroup.
    }

    /**
     * The trigger goes with the table; `actor_of_agency()` belongs to
     * 0001_01_01_000018_prepare_calendar and is dropped only there.
     */
    public function down(): void
    {
        Schema::dropIfExists('suspensions');
    }
};
