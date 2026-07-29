<?php
// database/migrations/2026_07_29_000001_add_ordered_qty_rate_to_order_lines.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the salesman actually asked for, kept apart from what the owner approved.
 *
 * Acceptance edits `order_lines.qty`/`rate` in place, so until now a shop
 * promised 10 packs at ₹90 and given 8 at ₹95 left no trace of the promise —
 * and the salesman found out at the door. These two columns are stamped once at
 * creation and never written again, so "was this edited?" is DERIVED by
 * comparing them with the live values rather than stored as a flag that could
 * drift.
 *
 * Nullable, and null means UNKNOWN, not "unchanged": rows written before this
 * migration may already hold an owner's edit, and copying those into the
 * originals would manufacture an authoritative-looking record that nothing
 * changed. Same restraint as sale_lines.list_rate, which is likewise never
 * backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->table('order_lines', function (Blueprint $table) {
            $table->integer('ordered_qty')->nullable()->after('qty');
            $table->decimal('ordered_rate', 10, 2)->nullable()->after('rate');
        });

        $this->backfill();
    }

    /**
     * The one backfill that reads history rather than inventing it: a PENDING
     * order has by definition not been through acceptance, so its current
     * qty/rate ARE the original. Every other status may already hold an
     * owner's edit and there is no way to know, so those stay null.
     *
     * Public and separate from up() so the WHERE clause — the only part that
     * can be wrong in a way that matters — is covered by a test rather than
     * trusted. Only ever fills a null, so re-running it cannot overwrite a
     * real original.
     */
    public function backfill(): void
    {
        DB::connection('pgsql_migrate')->statement(
            'UPDATE order_lines SET ordered_qty = qty, ordered_rate = rate
             WHERE ordered_qty IS NULL
               AND order_id IN (SELECT id FROM orders WHERE status = ?)',
            ['pending']
        );
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->table('order_lines', function (Blueprint $table) {
            $table->dropColumn(['ordered_qty', 'ordered_rate']);
        });
    }
};
