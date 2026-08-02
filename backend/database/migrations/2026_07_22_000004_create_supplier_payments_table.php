<?php
// database/migrations/2026_07_22_000004_create_supplier_payments_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->uuid('uuid'); // client/idempotency key
            $table->foreignUuid('supplier_id')->constrained('suppliers');
            $table->date('payment_date');
            $table->decimal('amount', 12, 2); // ₹ paid to the supplier
            $table->string('mode', 20);       // cash | upi | bank | other
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'uuid']); // also the business_id index
            $table->index(['business_id', 'supplier_id']); // supplier ledger
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
