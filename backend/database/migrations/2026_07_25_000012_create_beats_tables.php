<?php
// database/migrations/2026_07_25_000012_create_beats_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::create('beats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name', 80);
            // ISO weekdays (1 = Monday … 7 = Sunday) the beat is worked on.
            // JSON rather than a bitmask: the client filters "is today in here",
            // and an array reads the same in the database and in JavaScript.
            //
            // No DB-level default: MySQL forbids one on a JSON column. The empty
            // array comes from Beat::$attributes instead, so the column stays
            // NOT NULL and an insert that omits weekdays still gets `[]`.
            $table->jsonb('weekdays');
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

        Schema::create('beat_customers', function (Blueprint $table) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('beat_customers');
        Schema::dropIfExists('beats');
    }
};
