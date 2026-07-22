<?php
// database/migrations/2026_07_22_000005_add_purchase_id_to_stock_movements.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->table('stock_movements', function (Blueprint $table) {
            // Links a costed stock-in to the Purchase that created it (mirrors
            // production_batch_id). Null for movements recorded by hand.
            $table->foreignUuid('purchase_id')->nullable()->after('production_batch_id')
                ->constrained('purchases')->nullOnDelete();
            $table->index(['business_id', 'purchase_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['purchase_id']);
            $table->dropIndex(['business_id', 'purchase_id']);
            $table->dropColumn('purchase_id');
        });
    }
};
