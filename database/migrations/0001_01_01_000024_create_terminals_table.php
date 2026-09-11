<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A biometric device that captures timelogs (docs/design/03-terminals.md).
     *
     * Named `Terminal` and not `Device` by decision 16: Passport already owns
     * `devices` for OAuth device-authorization, which is a different thing
     * entirely.
     *
     * `agency_not_platform`, like `employees`, `workgroups` and `teams`
     * (decision 26): nothing operational hangs under the platform agency, and
     * a terminal is as operational as it gets — it belongs to one agency's
     * office and captures one agency's punches.
     *
     * `workgroup_id` is nullable and paired: a terminal may sit at a division
     * door or serve the whole agency from the lobby. MATCH SIMPLE skips the
     * paired check entirely once `workgroup_id` is null, so the agency-level
     * case needs no sentinel row.
     *
     * `host`, `port` and `secret` land unused. M5 ships file import only
     * (decision 40); the schema is complete or it is not, and a column added
     * later to a table people have already deployed is a migration nobody
     * wants. `secret` carries the `encrypted` cast on the model **from day
     * one** — the predecessor stored the same value in plaintext and leaked it
     * through four further channels, and a cast added after the first row is
     * written is a data migration, not a one-line change.
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
            // rather than an integer (decision 42). The predecessor made this
            // a smallint and then let a cascade rewrite every historical
            // timelog with it; a string cannot be arithmetic by accident and
            // cannot silently lose a leading zero.
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
            // The read offset for incremental pull and push (rule 5); null
            // means read everything. A **file import never advances it**
            // (decision 40) — a file is not an incremental device read, and
            // advancing it would make the first real pull skip records.
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

        // **Partial**, and that is the point: a serial is globally unique when
        // it is known, and a UNIQUE index would be enough on its own only if
        // every serial were known. It is not — so the index excludes nulls
        // explicitly rather than relying on NULLS DISTINCT, which says the
        // same thing but says it by accident. Global rather than per-agency:
        // a manufacturer's serial does not repeat across tenants, and two
        // agencies claiming one serial is a data-entry error worth refusing.
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
