<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A null workgroup is agency-wide under MATCH SIMPLE; operational deployment resolution remains
     * application logic.
     */
    public function up(): void
    {
        Schema::create('suspensions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            // Null: agency-wide. Set: this workgroup and its descendants.
            $table->ulid('workgroup_id')->nullable();
            $table->date('date');
            // The nullable time pair represents a whole-day suspension when absent.
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
            // A single-column actor key permits platform-superuser data entry.
            $table->ulid('user_id');
            // The declaration applies prospectively from its effective time.
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

        // This keeps the nullable time pair whole for unambiguous derivation.
        DB::statement('ALTER TABLE suspensions ADD CONSTRAINT suspensions_hours_paired CHECK ((starts IS NULL) = (ends IS NULL))');

        // Time windows require positive duration; zero suspends nothing.
        DB::statement('ALTER TABLE suspensions ADD CONSTRAINT suspensions_hours_ordered CHECK (starts IS NULL OR ends > starts)');

        // The recording user must belong to this agency or be a platform
        // user; no FK can say "or", so this trigger does. The function and
        // its reasoning are in 0001_01_01_000018_prepare_calendar.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER actor_of_agency
                BEFORE INSERT OR UPDATE OF user_id, agency_id ON suspensions
                FOR EACH ROW EXECUTE FUNCTION actor_of_agency();
        SQL);

        // Overlap is allowed because the deriver unions independent suspensions.
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
