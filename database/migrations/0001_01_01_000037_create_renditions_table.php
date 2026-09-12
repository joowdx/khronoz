<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renditions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->ulid('ledger_id');
            $table->unsignedInteger('revision');
            $table->string('template');
            $table->jsonb('snapshot');
            $table->string('token', 64)->unique();
            $table->string('status')->default('unstored');
            $table->ulid('document_id')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->text('error')->nullable();
            $table->unique(['ledger_id', 'revision']);
            foreach (['ledger_id' => 'ledgers', 'document_id' => 'documents'] as $column => $parent) {
                $table->foreign([$column, 'agency_id'])->references(['id', 'agency_id'])->on($parent)->restrictOnDelete()->restrictOnUpdate();
            }
            $table->timestamps();
            $table->unique(['id', 'agency_id']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE renditions
                ADD CONSTRAINT renditions_revision_positive CHECK (revision > 0),
                ADD CONSTRAINT renditions_template_valid CHECK (template IN ('form48', 'plain')),
                ADD CONSTRAINT renditions_snapshot_object CHECK (jsonb_typeof(snapshot) = 'object'),
                ADD CONSTRAINT renditions_token_valid CHECK (token ~ '^[A-Za-z0-9_-]{32,64}$'),
                ADD CONSTRAINT renditions_status_valid CHECK (status IN ('unstored', 'pending', 'ready', 'failed')),
                ADD CONSTRAINT renditions_ready_document CHECK ((status = 'ready') = (document_id IS NOT NULL)),
                ADD CONSTRAINT renditions_unstored_clean CHECK (status <> 'unstored' OR (requested_at IS NULL AND generated_at IS NULL AND failed_at IS NULL)),
                ADD CONSTRAINT renditions_archive_requested CHECK (status = 'unstored' OR requested_at IS NOT NULL),
                ADD CONSTRAINT renditions_ready_generated CHECK (status <> 'ready' OR generated_at IS NOT NULL),
                ADD CONSTRAINT renditions_failed_at CHECK (status <> 'failed' OR failed_at IS NOT NULL);
            CREATE UNIQUE INDEX renditions_one_current ON renditions (ledger_id) WHERE superseded_at IS NULL;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION renditions_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'a rendition audit record cannot be deleted';
                END IF;

                IF (to_jsonb(OLD) - ARRAY['status', 'document_id', 'requested_at', 'generated_at', 'failed_at', 'superseded_at', 'error', 'updated_at'])
                    IS DISTINCT FROM
                   (to_jsonb(NEW) - ARRAY['status', 'document_id', 'requested_at', 'generated_at', 'failed_at', 'superseded_at', 'error', 'updated_at']) THEN
                    RAISE EXCEPTION 'a frozen rendition cannot be rewritten';
                END IF;

                IF OLD.superseded_at IS NOT NULL AND OLD.superseded_at IS DISTINCT FROM NEW.superseded_at THEN
                    RAISE EXCEPTION 'rendition supersession is one way';
                END IF;

                IF OLD.status = 'ready' AND (
                    NEW.status IS DISTINCT FROM OLD.status
                    OR NEW.document_id IS DISTINCT FROM OLD.document_id
                    OR NEW.generated_at IS DISTINCT FROM OLD.generated_at
                ) THEN
                    RAISE EXCEPTION 'a ready rendition document cannot be replaced';
                END IF;

                IF NOT (
                    NEW.status = OLD.status
                    OR (OLD.status = 'pending' AND NEW.status IN ('ready', 'failed'))
                    OR (OLD.status = 'failed' AND NEW.status = 'pending')
                ) THEN
                    RAISE EXCEPTION 'invalid rendition status transition';
                END IF;

                RETURN NEW;
            END $$;

            CREATE TRIGGER renditions_immutable
                BEFORE UPDATE OR DELETE ON renditions
                FOR EACH ROW EXECUTE FUNCTION renditions_immutable();
        SQL);
        AppRoleGrants::restrict();
    }

    public function down(): void
    {
        Schema::dropIfExists('renditions');
        DB::statement('DROP FUNCTION IF EXISTS renditions_immutable()');
    }
};
