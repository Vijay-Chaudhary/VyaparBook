<?php
// database/migrations/2026_07_17_100002_create_stock_movements_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->uuid('uuid'); // client-generated idempotency key
            $table->foreignUuid('raw_material_id')->constrained('raw_materials')->cascadeOnDelete();
            $table->date('movement_date');
            $table->string('kind', 10); // in | out | adjust — labels intent
            // Signed effect on stock: + raises on-hand, − lowers it. The controller
            // derives the sign from kind + magnitude, so Σ qty is the on-hand total
            // and an `out` can never raise stock. 3 decimals mirror reorder_level.
            $table->decimal('qty', 12, 3);
            $table->string('note', 255)->nullable();
            // foreignId (bigint), not foreignUuid: users.id is a bigint auto-key.
            $table->foreignId('created_by')->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->bigInteger('sync_seq');
            $table->timestamps();
            // production_batch_id is added in a later migration, once production_batches
            // exists — Stock stands alone as the foundation first.

            $table->unique(['business_id', 'uuid']); // also the business_id index
            $table->index(['business_id', 'raw_material_id']); // per-material ledger
            $table->index(['business_id', 'sync_seq']); // delta pull
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
