<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One ingestion run (docs/design/03-terminals.md).
     *
     * This table exists because the predecessor had nothing like it. Its
     * counts lived only in a fired event — one of which had no registered
     * listener at all, so every one of those was silently dropped — and the
     * number it reported was the size of the *input*, not the number of rows
     * actually inserted, because its upsert was `ON CONFLICT DO UPDATE` and
     * could not tell the two apart. "What did that import actually do" was
     * unanswerable afterwards.
     *
     * `syncs_counts_balance` is what makes the answer trustworthy: the four
     * counters must add up, so a writer cannot report 500 received and 400
     * accounted for. It has a consequence the writer must respect — the
     * counters default to 0, which satisfies the CHECK, and must then be set
     * **in one UPDATE** at the end of the run. Incrementing them as the import
     * streams fires 23514 on the first row.
     *
     * `earliest` and `latest` are not in the original ERD and are here because
     * of a specific predecessor defect: it took its run's time span from the
     * *first and last rows of the file* rather than from the minimum and
     * maximum timestamps, so an export listing 30 September before 1 September
     * produced an inverted range, and every row it had just inserted was
     * silently skipped by the recompute that followed. These are `min()` and
     * `max()` accumulated over the stream, and M6 needs a window it can trust.
     *
     * `reference` is the source filename for an import — the same column name
     * `holidays`, `suspensions` and `exemptions` use for "the paper this came
     * from". The predecessor kept the filename only in the dropped event.
     *
     * `drift` is the device clock skew observed in this run. A measurement for
     * alerts and disputes, never a correction: `timelogs.time` is never
     * adjusted (rule 4). Null for an import, which has no device clock to read.
     *
     * REVOKE DELETE on this table lands with the timelogs privileges: a run
     * record that can be deleted is a run that can be denied.
     */
    public function up(): void
    {
        Schema::create('syncs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('terminal_id');
            $table->string('trigger');
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->smallInteger('drift')->nullable();
            // `received` is **rows accounted for**, not lines read, and the
            // difference only shows on the failure path: a run that dies with
            // a chunk still buffered never accounts for those rows, so they
            // appear in no counter. That is deliberate rather than a gap —
            // deriving `received` from the other three is what makes
            // syncs_counts_balance unbreakable by the writer, and a run that
            // stopped early says so in `status` and `error`. Raised by the
            // adversarial review of 2026-09-11 as a possible mismatch between
            // the column's name and its content; recorded here so the name is
            // read the way the writer means it.
            $table->integer('received')->default(0);
            $table->integer('accepted')->default(0);
            $table->integer('duplicates')->default(0);
            $table->integer('rejected')->default(0);
            // The filename an import came from. Nullable: a pull or a push has
            // no file.
            $table->string('reference')->nullable();
            // The span this run actually ingested — min() and max() over the
            // stream, never the first and last rows read.
            $table->timestamp('earliest')->nullable();
            $table->timestamp('latest')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['id', 'agency_id']);

            $table->foreign(['terminal_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('terminals')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        // Target for the paired (sync_id, terminal_id) FK on timelogs.
        // Trivially satisfied — `id` is already unique — and it makes "this
        // punch came from this terminal's run" structural. A single-column FK
        // on sync_id alone would let a punch claim another terminal's run.
        DB::statement('ALTER TABLE syncs ADD CONSTRAINT syncs_id_terminal_id_unique UNIQUE (id, terminal_id)');

        DB::statement("ALTER TABLE syncs ADD CONSTRAINT syncs_trigger_valid CHECK (trigger IN ('scheduled', 'manual', 'push', 'import'))");
        DB::statement("ALTER TABLE syncs ADD CONSTRAINT syncs_status_valid CHECK (status IN ('running', 'completed', 'failed'))");

        DB::statement('ALTER TABLE syncs ADD CONSTRAINT syncs_times_ordered CHECK (finished_at IS NULL OR finished_at >= started_at)');

        // The counters must add up. Not tidiness: this is the only thing
        // standing between a reader and a run that claims to have received
        // more rows than it can account for.
        DB::statement('ALTER TABLE syncs ADD CONSTRAINT syncs_counts_balance CHECK (received = accepted + duplicates + rejected)');

        // And they must be non-negative. A second constraint rather than one
        // compound CHECK, deliberately: they fail for different reasons and a
        // test should be able to tell which. Balance alone accepts
        // received = 0, accepted = -4, duplicates = 4, rejected = 0 — an
        // impossible completed run that still adds up.
        DB::statement('ALTER TABLE syncs ADD CONSTRAINT syncs_counts_nonnegative CHECK (received >= 0 AND accepted >= 0 AND duplicates >= 0 AND rejected >= 0)');

        // A span is both bounds or neither — the same "resolved means both"
        // discipline `timelogs_resolved_pair` applies. A run that inserted
        // nothing has no span, and half a span is a bug, not a state.
        DB::statement('ALTER TABLE syncs ADD CONSTRAINT syncs_span_paired CHECK ((earliest IS NULL) = (latest IS NULL))');

        // And ordered, which the predecessor's first-and-last-row span was not.
        DB::statement('ALTER TABLE syncs ADD CONSTRAINT syncs_span_ordered CHECK (latest IS NULL OR latest >= earliest)');

        // A run record that can be deleted is a run that can be denied. The
        // statement lives in AppRoleGrants::restrict() so `db:grant` restores
        // it; see that method and the timelogs migration.
        AppRoleGrants::restrict();
    }

    public function down(): void
    {
        Schema::dropIfExists('syncs');
    }
};
