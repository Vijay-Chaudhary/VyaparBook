<?php
// database/migrations/2026_07_25_000012_create_beats_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salesman beats (PRD §18 Phase 3, "salesman route/beat planning").
 *
 * A beat is a named set of customers worked on fixed weekdays — "Rampur runs
 * Mondays and Thursdays" — which is how FMCG distribution actually schedules,
 * and it repeats forever without anyone maintaining a calendar.
 *
 * These are the FIRST new offline-synced entities since the core, so they carry
 * sync_seq and stream down the existing delta pull. They are pure SERVER data:
 * the phone reads them and never writes them, so there is no push path, no
 * idempotency key and no conflict rule to get wrong. Recording visits from the
 * field would need all three and is deliberately a later phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('beats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name', 80);
            // ISO weekdays (1 = Monday … 7 = Sunday) the beat is worked on.
            // jsonb rather than a bitmask: the client filters "is today in here",
            // and an array reads the same in Postgres, JSON and JavaScript.
            $table->jsonb('weekdays')->default('[]');
            // The salesman who works it. Nullable: a beat can be planned before
            // anyone is assigned, and a user may be removed from the business.
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->bigInteger('sync_seq');
            $table->timestamps();

            $table->unique(['business_id', 'name']);
            $table->index(['business_id', 'sync_seq']);   // delta pull
        });

        Schema::connection('pgsql_migrate')->create('beat_customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('beat_id')->constrained('beats')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            // Call order along the route, so the phone lists them in the order
            // the salesman actually walks.
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->bigInteger('sync_seq');
            $table->timestamps();

            // One customer appears once per beat; the same customer may belong
            // to more than one beat.
            $table->unique(['beat_id', 'customer_id']);
            $table->index(['business_id', 'sync_seq']);
        });

        foreach (['beats', 'beat_customers'] as $table) {
            DB::connection('pgsql_migrate')->statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::connection('pgsql_migrate')->statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::connection('pgsql_migrate')->statement(
                "CREATE POLICY {$table}_isolation ON {$table}
                 USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
                 WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)"
            );
        }
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('beat_customers');
        Schema::connection('pgsql_migrate')->dropIfExists('beats');
    }
};
