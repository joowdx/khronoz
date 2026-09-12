<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Date ranges represent continuous leave; the future workday pair proves an exemption belongs to its employee.
     */
    public function up(): void
    {
        Schema::create('exemptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('employee_id');
            // The first day, and the only day unless `until` is set.
            $table->date('date');
            // Inclusive NOT NULL bounds prevent an accidental unbounded exemption.
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
            // Approval is required because the deriver has no pending state.
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->unique(['id', 'agency_id']);

            // This target supports the future paired workday key.
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

        // Inclusive bounds represent one day as `until = date`.
        DB::statement('ALTER TABLE exemptions ADD CONSTRAINT exemptions_span_ordered CHECK (until >= date)');

        // The nullable time pair, held whole for the reason
        // suspensions_hours_paired is: "excused from 10:00 until nothing"
        // leaves the deriver guessing an end.
        DB::statement('ALTER TABLE exemptions ADD CONSTRAINT exemptions_hours_paired CHECK ((starts IS NULL) = (ends IS NULL))');
        DB::statement('ALTER TABLE exemptions ADD CONSTRAINT exemptions_hours_ordered CHECK (starts IS NULL OR ends > starts)');

        // Multi-day exemptions cannot carry a partial-day window.
        DB::statement('ALTER TABLE exemptions ADD CONSTRAINT exemptions_span_is_whole_days CHECK (until = date OR starts IS NULL)');

        // The recording user must belong to this agency or be a platform
        // user; no FK can say "or", so this trigger does. The function and
        // its reasoning are in 0001_01_01_000018_prepare_calendar.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER actor_of_agency
                BEFORE INSERT OR UPDATE OF user_id, agency_id ON exemptions
                FOR EACH ROW EXECUTE FUNCTION actor_of_agency();
        SQL);

        // Overlap is allowed; the deriver selects the applicable exemption.
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
