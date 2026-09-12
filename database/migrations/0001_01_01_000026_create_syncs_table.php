<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Counts are finalized in one update; earliest and latest are stream extrema, not file order.
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
            // Received counts accounted rows; partial runs report status and error.
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

        // This paired target prevents a timelog from naming another terminal's run.
        DB::statement('ALTER TABLE syncs ADD CONSTRAINT syncs_id_terminal_id_unique UNIQUE (id, terminal_id)');

        DB::statement("ALTER TABLE syncs ADD CONSTRAINT syncs_trigger_valid CHECK (trigger IN ('scheduled', 'manual', 'push', 'import'))");
        DB::statement("ALTER TABLE syncs ADD CONSTRAINT syncs_status_valid CHECK (status IN ('running', 'completed', 'failed'))");

        DB::statement('ALTER TABLE syncs ADD CONSTRAINT syncs_times_ordered CHECK (finished_at IS NULL OR finished_at >= started_at)');

        // The counters must add up. Not tidiness: this is the only thing
        // standing between a reader and a run that claims to have received
        // more rows than it can account for.
        DB::statement('ALTER TABLE syncs ADD CONSTRAINT syncs_counts_balance CHECK (received = accepted + duplicates + rejected)');

        // Non-negative counts supplement the balance constraint.
        DB::statement('ALTER TABLE syncs ADD CONSTRAINT syncs_counts_nonnegative CHECK (received >= 0 AND accepted >= 0 AND duplicates >= 0 AND rejected >= 0)');

        // A span is both bounds or neither — the same "resolved means both"
        // discipline `timelogs_resolved_pair` applies. A run that inserted
        // nothing has no span, and half a span is a bug, not a state.
        DB::statement('ALTER TABLE syncs ADD CONSTRAINT syncs_span_paired CHECK ((earliest IS NULL) = (latest IS NULL))');

        // And ordered: a span taken from the file's first and last rows rather
        // than its minimum and maximum inverts itself on an unordered export.
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
