<?php
// database/migrations/2026_07_25_000013_add_list_rate_to_sale_lines.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the pack's default price WAS on the day of the sale.
 *
 * Nullable and never backfilled: rows written before this shipped genuinely
 * have no such value, and inventing one from today's default would make future
 * discount analysis wrong while looking authoritative. Null means unknown.
 *
 * Server-authored — never accepted from the client, or a phone could claim it
 * sold at list while charging less.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->table('sale_lines', function (Blueprint $table) {
            $table->decimal('list_rate', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->table('sale_lines', function (Blueprint $table) {
            $table->dropColumn('list_rate');
        });
    }
};
