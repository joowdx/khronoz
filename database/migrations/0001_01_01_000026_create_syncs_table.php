<?php

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

        DB::statement("ALTER TABLE syncs ADD CONSTRAINT syncs_trigger_valid CHECK (trigger IN ('scheduled', 'manual', 'push', 'import'))");
        DB::statement("ALTER TABLE syncs ADD CONSTRAINT syncs_status_valid CHECK (status IN ('running', 'completed', 'failed'))");

        DB::statement('ALTER TABLE syncs ADD CONSTRAINT syncs_times_ordered CHECK (finished_at IS NULL OR finished_at >= started_at)');

        // The counters must add up. Not tidiness: this is the only thing
        // standing between a reader and a run that claims to have received
        // more rows than it can account for.
        DB::statement('ALTER TABLE syncs ADD CONSTRAINT syncs_counts_balance CHECK (received = accepted + duplicates + rejected)');

        // A span is both bounds or neither — the same "resolved means both"
        // discipline `timelogs_resolved_pair` applies. A run that inserted
        // nothing has no span, and half a span is a bug, not a state.
        DB::statement('ALTER TABLE syncs ADD CONSTRAINT syncs_span_paired CHECK ((earliest IS NULL) = (latest IS NULL))');

        // And ordered, which the predecessor's first-and-last-row span was not.
        DB::statement('ALTER TABLE syncs ADD CONSTRAINT syncs_span_ordered CHECK (latest IS NULL OR latest >= earliest)');
    }

    public function down(): void
    {
        Schema::dropIfExists('syncs');
    }
};
