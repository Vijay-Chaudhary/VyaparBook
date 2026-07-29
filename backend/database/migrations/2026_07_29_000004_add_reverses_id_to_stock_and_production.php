<?php
// database/migrations/2026_07_29_000004_add_reverses_id_to_stock_and_production.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections for stock and production, the same way sales and payments do it.
 *
 * On-hand is Σ `stock_movements.qty` and finished goods is Σ
 * `production_batches.output_kg` — nothing is stored, everything is summed. So a
 * correction can only ever be a NEW row with the amounts negated, pointing back
 * at what it cancels. Deleting a movement would silently restate on-hand,
 * finished goods and the COGS per-kg rate derived from both.
 *
 * Self-referencing and nullable: null is the ordinary case, and a row that
 * carries one IS a correction. No cascade — a correction must not disappear
 * with its original, or the sums it balances would go wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->table('stock_movements', function (Blueprint $table) {
            $table->foreignUuid('reverses_id')->nullable()->constrained('stock_movements');
        });

        Schema::connection('pgsql_migrate')->table('production_batches', function (Blueprint $table) {
            $table->foreignUuid('reverses_id')->nullable()->constrained('production_batches');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reverses_id');
        });

        Schema::connection('pgsql_migrate')->table('production_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reverses_id');
        });
    }
};
