<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `workgroup_id` is optional under MATCH SIMPLE; terminal secrets require encryption from the first row.
     */
    public function up(): void
    {
        Schema::create('terminals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            // Nullable: an agency-wide terminal hangs under no workgroup. The
            // pair is constrained below rather than here, since Blueprint has
            // no composite foreignUlid.
            $table->ulid('workgroup_id')->nullable();
            // The device number **as it appears in the attlog**, and a string
            // rather than an integer (decision 42): a string cannot be
            // arithmetic by accident and cannot silently lose a leading zero.
            $table->string('code');
            $table->string('name');
            // Nullable because a device's serial is often not to hand when it
            // is registered, and the unique index below is partial for exactly
            // that reason.
            $table->string('serial')->nullable();
            $table->string('kind');
            $table->string('protocol');
            $table->string('host')->nullable();
            $table->integer('port')->nullable();
            // text, not string: a vendor comm key has no length the schema
            // should assert, and Laravel's `encrypted` cast inflates whatever
            // is stored well past a varchar(255).
            $table->text('secret')->nullable();
            // Observed clock skew in seconds. A measurement for alerts and
            // disputes, never a correction: `timelogs.time` is never adjusted
            // (03-terminals.md rule 4). Signed, since a device may run fast.
            $table->smallInteger('drift')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            // File imports never advance this incremental-device offset.
            $table->string('stamp')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            // FK target for enrollments, syncs and timelogs, all of which pair
            // their terminal against this row's agency.
            $table->unique(['id', 'agency_id']);

            // The attlog identifies its device by `code`, so two terminals of
            // one agency cannot share one.
            $table->unique(['agency_id', 'code']);

            $table->foreign(['workgroup_id', 'agency_id'])
                ->references(['id', 'agency_id'])
                ->on('workgroups')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        // A known manufacturer serial is globally unique; unknown serials remain allowed.
        DB::statement('CREATE UNIQUE INDEX terminals_serial ON terminals (serial) WHERE serial IS NOT NULL');

        DB::statement("ALTER TABLE terminals ADD CONSTRAINT terminals_kind_valid CHECK (kind IN ('terminal', 'usb'))");
        DB::statement("ALTER TABLE terminals ADD CONSTRAINT terminals_protocol_valid CHECK (protocol IN ('push', 'pull', 'file'))");

        // Nothing operational hangs under the platform agency. The function and
        // its reasoning are in 0001_01_01_000008_prepare_organization.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER agency_not_platform
                BEFORE INSERT OR UPDATE OF agency_id ON terminals
                FOR EACH ROW EXECUTE FUNCTION agency_not_platform();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('terminals');
    }
};
