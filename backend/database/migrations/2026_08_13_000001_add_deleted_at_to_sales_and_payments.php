<?php
// database/migrations/2026_08_13_000001_add_deleted_at_to_sales_and_payments.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a sale or payment outright, instead of writing a reversal row.
 *
 * The owner asked for edit and delete rather than "sale ₹500, voided ₹500" on
 * the statement — so a deleted entry has to LEAVE the khata, not sit in it
 * paired with its mirror. Soft, not hard, because the row is still pointed at:
 * invoices.sale_id and orders.sale_id are real FKs, and a removed row would
 * orphan them. Keeping it also makes the delete undoable, which a khata
 * correction has to be — the wrong row gets tapped.
 *
 * Indexed together with business_id: every khata read filters on both, and an
 * unindexed deleted_at turns each of those into a scan of the tenant's sales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->softDeletes();
            $table->index(['business_id', 'deleted_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->softDeletes();
            $table->index(['business_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'deleted_at']);
            $table->dropSoftDeletes();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};
