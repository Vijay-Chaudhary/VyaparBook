<?php
// database/migrations/2026_07_25_000006_create_reminder_batches_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One scheduled reminder run for one tenant on one day (Phase 4c).
 *
 * Holds only what the RUN did — the messages themselves are reminder_logs rows
 * with status 'planned', so there is a single timeline rather than a plan table
 * and a sent table that can disagree.
 *
 * The unique (business_id, scheduled_for) is the idempotency guarantee: cron
 * double-fires, jobs retry and humans run commands by hand, and none of those
 * may produce a second set of messages for the same day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('reminder_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->date('scheduled_for');
            // planned | sent | cancelled | skipped
            $table->string('status', 12)->default('planned');
            $table->integer('planned_count')->default(0);
            $table->integer('sent_count')->default(0);
            // Why a run stopped or did nothing: daily_cap | quiet_hours |
            // transport_disabled | automation_off. Never leave it silent.
            $table->string('stopped_reason', 24)->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'scheduled_for']);
        });

        // Online-only: no version/sync_seq, never enters offline sync.

        DB::connection('pgsql_migrate')->statement('ALTER TABLE reminder_batches ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE reminder_batches FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY reminder_batches_isolation ON reminder_batches
            USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
            WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
        SQL);

        Schema::connection('pgsql_migrate')->table('reminder_logs', function (Blueprint $table) {
            // Null for a manual 4a/4b send; set for anything a run planned.
            // Also how the cooldown tells automated history from manual taps.
            $table->foreignUuid('batch_id')->nullable()->constrained('reminder_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->table('reminder_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
        });

        Schema::connection('pgsql_migrate')->dropIfExists('reminder_batches');
    }
};
