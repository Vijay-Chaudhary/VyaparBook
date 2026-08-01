<?php
// database/migrations/2026_07_25_000011_create_invoices_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax invoices (PRD Phase 3).
 *
 * An invoice is the app's deliberate exception to "always recomputable"
 * (PRD §9): a filed tax document is a snapshot by law. Its lines freeze the
 * description, HSN, rate and tax split at the moment of issue, so a later change
 * to a product's rate cannot alter what a filed document says.
 *
 * invoice_counters exists to make numbering gapless. MAX(seq)+1 lets two
 * concurrent requests read the same maximum, and a unique index would turn that
 * into an error rather than a correct number — so a counter row is locked
 * FOR UPDATE and incremented instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_counters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('financial_year', 7);   // e.g. 2026-27, April–March
            $table->unsignedInteger('next_seq')->default(1);
            $table->timestamps();

            $table->unique(['business_id', 'financial_year']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            // One invoice per sale: re-invoicing must never be a silent second
            // document. Cancellation and reissue is a deliberate later feature.
            $table->foreignUuid('sale_id')->unique()->constrained('sales');
            $table->string('number', 20);          // 2026-27/0001
            $table->string('financial_year', 7);
            $table->unsignedInteger('seq');
            $table->date('issued_on');

            // Buyer snapshot. GSTIN lives here rather than on customers, which
            // is offline-synced — a field only invoicing uses must not reach
            // into Dexie and the React forms.
            $table->string('buyer_name', 120);
            $table->string('buyer_village', 120)->nullable();
            $table->string('buyer_gstin', 15)->nullable();

            // Seller snapshot: a filed invoice must keep showing what it said,
            // even if the shop later edits its own details.
            $table->string('seller_gstin', 15);
            $table->string('seller_state_code', 2)->nullable();

            $table->decimal('taxable_total', 12, 2);
            $table->decimal('cgst_total', 12, 2);
            $table->decimal('sgst_total', 12, 2);
            // Equals sales.total exactly — the invoice cannot disagree with the
            // khata about what the customer owes.
            $table->decimal('grand_total', 12, 2);

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['business_id', 'financial_year', 'seq']);
            $table->index(['business_id', 'issued_on']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('description', 180);
            $table->string('hsn_code', 8)->nullable();
            $table->integer('qty');                 // negative = a return line
            $table->decimal('rate', 10, 2);
            $table->decimal('taxable_value', 12, 2);
            $table->decimal('gst_rate_percent', 5, 2);
            $table->decimal('cgst', 12, 2);
            $table->decimal('sgst', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->index(['business_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_counters');
    }
};
