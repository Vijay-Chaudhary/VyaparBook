<?php
// database/migrations/2026_07_17_000005_create_payments_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            // Client-generated idempotency key: a payment retried over a flaky link
            // resolves to the same row, never a double-credit. Unique per tenant.
            $table->uuid('uuid');
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 12, 2); // positive = customer paid; a reversal negates
            $table->string('mode', 20); // cash | upi | cheque | bank | other
            // foreignId (bigint), not foreignUuid: users.id is a bigint auto-key.
            $table->foreignId('created_by')->constrained('users');
            // Append-only reversal: a correction is a NEW payment row pointing back
            // here with a negated amount. The self-FK is added below, after the PK.
            $table->uuid('reverses_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->bigInteger('sync_seq');
            $table->timestamps();

            $table->unique(['business_id', 'uuid']); // also the business_id index
            $table->index(['business_id', 'customer_id']); // khata per customer
            $table->index(['business_id', 'sync_seq']); // delta pull
        });

        // Self-referencing FK added now that payments(id) exists as a PK.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('reverses_id')->references('id')->on('payments');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
