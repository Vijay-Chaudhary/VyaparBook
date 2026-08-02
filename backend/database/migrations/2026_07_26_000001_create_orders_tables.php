<?php
// database/migrations/2026_07_26_000001_create_orders_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders: what a shop asked for, before anyone owes anything.
 *
 * Separate from `sales` on purpose. A sale row IS a khata entry, and every
 * money figure in the app is built on that; an order is the stage before, so
 * outstanding, cash flow, COGS, invoicing and reminders need no change at all.
 *
 * Offline-synced (sync_seq + version) because orders are taken in villages with
 * no signal. `uuid` is the client's idempotency key — and the sale created on
 * delivery reuses it, so a replayed delivery cannot double a khata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->uuid('uuid');
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->date('order_date');
            $table->string('status', 12)->default('pending');
            $table->decimal('total', 12, 2);

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('accepted_by')->nullable()->constrained('users');
            $table->timestamp('accepted_at')->nullable();
            // Why an order was rejected or cancelled. Optional: an unexplained
            // rejection is unhelpful, not invalid.
            $table->string('status_note', 255)->nullable();
            // What it became. Null until delivered.
            $table->foreignUuid('sale_id')->nullable()->constrained('sales');

            $table->unsignedInteger('version')->default(1);
            $table->bigInteger('sync_seq');
            $table->timestamps();

            $table->unique(['business_id', 'uuid']);
            $table->index(['business_id', 'sync_seq']);   // delta pull
            $table->index(['business_id', 'status']);     // the owner's pending list
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('product_pack_id')->constrained('product_packs');
            $table->integer('qty');
            $table->decimal('rate', 10, 2);
            $table->decimal('line_total', 12, 2);
            // No list_rate: that is authored server-side when the sale is
            // created, so an order has no business carrying it.
            $table->unsignedInteger('version')->default(1);
            $table->bigInteger('sync_seq');
            $table->timestamps();

            $table->index(['business_id', 'order_id']);
            $table->index(['business_id', 'sync_seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};
