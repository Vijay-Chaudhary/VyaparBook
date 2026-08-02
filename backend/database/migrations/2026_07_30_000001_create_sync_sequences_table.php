<?php
// database/migrations/2026_07_30_000001_create_sync_sequences_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One monotonic counter per tenant, replacing Postgres's `sync_seq_global`.
 *
 * MySQL has no sequences. The obvious emulation is a single counter row, but
 * that would serialise EVERY write on the platform against one row lock.
 *
 * sync_seq only ever needs to be monotonic WITHIN a tenant: the delta pull is
 * always `business_id = X AND sync_seq > cursor`, and each device holds a
 * per-tenant Dexie database and a per-tenant cursor. A global sequence was
 * stronger than the invariant required, so contention drops to one shop's own
 * writes — which mostly serialise anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_sequences', function (Blueprint $table) {
            // PK, not just an index: one row per tenant is the whole design, and
            // a duplicate row would hand out the same sync_seq twice.
            $table->foreignUuid('business_id')->primary()
                ->constrained('businesses')->cascadeOnDelete();
            $table->unsignedBigInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_sequences');
    }
};
