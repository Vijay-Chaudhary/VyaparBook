<?php
// database/migrations/2026_07_17_100003_create_production_batches_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->uuid('uuid'); // client-generated idempotency key
            // The finished good produced. No cascadeOnDelete: the catalog archives
            // products, never deletes them, so a historical batch stays resolvable.
            $table->foreignUuid('product_id')->constrained('products');
            $table->date('batch_date');
            $table->decimal('output_kg', 12, 3); // finished quantity produced
            // foreignId (bigint), not foreignUuid: users.id is a bigint auto-key.
            $table->foreignId('created_by')->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->bigInteger('sync_seq');
            $table->timestamps();

            $table->unique(['business_id', 'uuid']); // also the business_id index
            $table->index(['business_id', 'sync_seq']); // delta pull
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batches');
    }
};
