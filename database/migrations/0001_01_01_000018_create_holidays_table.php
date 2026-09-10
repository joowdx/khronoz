<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A date on which no work is expected, or on which work is paid at a
     * premium (docs/design/05-calendar.md).
     *
     * No `agency_not_platform` trigger, and deliberately so: the platform
     * agency is exactly where national holidays live, the same arrangement
     * `shifts` and `schedules` have and the opposite of `employees`,
     * `workgroups` and `teams`. That is also why this is the one table whose
     * scope is `agency_id IN (own, platform)` rather than `agency_id = own`
     * (07-constraints.md, "Global rows belong to the platform agency").
     *
     * Two columns that look similar and answer different questions. `agency_id`
     * is the **scope** — who the holiday applies to, the platform row meaning
     * everyone. `type` is the **rate** — what a worked or unworked day is worth.
     * Nothing forbids `type = 'local'` on the platform row: an LGU holiday
     * declared nationally is odd phrasing but operationally identical to a
     * special day declared nationally, which is legitimate, so the oddity is
     * conceptual rather than a violation and no trigger tries to catch it.
     */
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->date('date');
            // NOT NULL, and that is load-bearing rather than tidiness: `name`
            // is the third column of the unique key below, and a UNIQUE index
            // treats nulls as distinct by default (NULLS DISTINCT), so a
            // nullable name would let an unlimited number of unnamed rows
            // pile up on one date — defeating the only duplicate protection
            // this table has.
            $table->string('name');
            $table->string('type');
            // The proclamation or ordinance number. Nullable: an agency may
            // know a holiday is coming before the paper reaches them, and a
            // refusal here would leave them unable to record a date they can
            // see on the news.
            $table->string('reference')->nullable();
            // When the declaration took effect. Workdays before it are not
            // recomputed (Res. 2600838 §2.5), so this is prospective and the
            // timekeeper may enter the proclamation's own time rather than
            // the moment of data entry. Nothing computes a workday until
            // Milestone 6; M4 stores and exposes it.
            $table->timestamp('declared_at');
            $table->timestamps();

            $table->unique(['id', 'agency_id']);

            // **Coincident holidays must not collapse** (dole-rules.md
            // section I item 6). Eid al-Fitr landing on Bonifacio Day, or a
            // city charter day on a national special day, is two holidays on
            // one date and both are owed — under DOLE's rule the higher rate
            // applies and the day may attract both premiums, so a schema that
            // kept only one row would silently discard the more expensive
            // one.
            //
            // `name` is therefore in the key and `(agency_id, date)` is not.
            // The cost is that this permits the same holiday twice under two
            // spellings; the alternative loses money, so the trade is made
            // deliberately in this direction.
            //
            // Every consumer must read **all** rows for a date and never
            // ->first(). That is the single most likely silent defect in this
            // milestone: a firstWhere('date', $d) compiles, passes a naive
            // test, and quietly drops the higher-rate holiday.
            $table->unique(['agency_id', 'date', 'name']);
        });

        DB::statement("ALTER TABLE holidays ADD CONSTRAINT holidays_type_valid CHECK (type IN ('regular', 'special', 'working', 'local'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
