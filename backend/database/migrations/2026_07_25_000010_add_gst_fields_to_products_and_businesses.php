<?php
// database/migrations/2026_07_25_000010_add_gst_fields_to_products_and_businesses.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GST reference data (PRD Phase 3).
 *
 * products is offline-synced, so these two columns are SERVER-SIDE ONLY: they
 * are deliberately not added to the API whitelist, leaving the sync payload and
 * the Dexie schema untouched — the same treatment customers.reminder_opt_out_at
 * got in Phase 4a. They are set from a Blade owner screen, not the React app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // HSN is per product and appears on the invoice line.
            $table->string('hsn_code', 8)->nullable();
            // Nullable: falls back to the shop default, so a shop selling at one
            // rate configures it once rather than product by product.
            $table->decimal('gst_rate_percent', 5, 2)->nullable();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->decimal('default_gst_rate_percent', 5, 2)->nullable();
            // The two-digit GST state code, printed on the invoice.
            $table->string('state_code', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['hsn_code', 'gst_rate_percent']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['default_gst_rate_percent', 'state_code']);
        });
    }
};
