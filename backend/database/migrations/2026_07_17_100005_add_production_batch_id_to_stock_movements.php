<?php
// database/migrations/2026_07_17_100005_add_production_batch_id_to_stock_movements.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Added after production_batches exists: an `out` movement written when a
    // batch is completed references that batch, so "why did stock drop" is
    // answerable. A movement recorded by hand leaves it null. Stock stayed the
    // foundation (created first); Production layers this trace link on top.
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignUuid('production_batch_id')->nullable()->after('note')
                ->constrained('production_batches');
            $table->index(['business_id', 'production_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['production_batch_id']);
            $table->dropIndex(['business_id', 'production_batch_id']);
            $table->dropColumn('production_batch_id');
        });
    }
};
