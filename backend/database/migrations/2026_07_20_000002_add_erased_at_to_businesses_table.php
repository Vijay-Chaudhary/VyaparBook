<?php
// database/migrations/2026_07_20_000002_add_erased_at_to_businesses_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The erasure tombstone (PRD §13 — DPDP right to erasure).
 *
 * A DPDP erasure purges every tenant-owned row and blanks the business's own
 * identifying fields, but keeps the businesses row itself: platform_audit_logs
 * references it, and that trail is the proof of who ordered the erasure and
 * when. Deleting the row would either break the FK or orphan the very record
 * that demonstrates compliance. So the data goes and a dated marker stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->table('businesses', function (Blueprint $table) {
            $table->timestamp('erased_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->table('businesses', function (Blueprint $table) {
            $table->dropColumn('erased_at');
        });
    }
};
