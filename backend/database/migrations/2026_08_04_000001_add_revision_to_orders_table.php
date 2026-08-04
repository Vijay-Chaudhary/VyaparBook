<?php
// database/migrations/2026_08_04_000001_add_revision_to_orders_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counts how many times an owner has corrected a delivered order.
 *
 * It exists to key the corrected SALE, not to describe the order. LedgerWriter
 * ::createSale short-circuits on uuid, and OrderWriter::deliver keys the sale on
 * the order's own uuid — so a correction re-using that uuid would be handed the
 * original sale back and silently change nothing. Each revision derives its own
 * uuid from this counter, which also makes a double-submitted correction replay
 * onto the same sale instead of writing a second one.
 *
 * Starts at 0: an order that has never been corrected is revision 0, and its
 * sale is the one keyed on the bare order uuid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(0)->after('status_note');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
