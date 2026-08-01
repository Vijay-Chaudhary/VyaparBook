<?php
// database/migrations/2026_07_17_100001_create_raw_materials_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_materials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->uuid('uuid'); // client-generated idempotency key
            $table->string('name', 120);
            $table->string('unit', 20); // kg | litre | piece | … (validated in the controller)
            // 3 decimals mirror pack_sizes.weight_kg — materials are weighed (0.5 kg).
            $table->decimal('reorder_level', 12, 3)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->bigInteger('sync_seq');
            $table->timestamps();

            $table->unique(['business_id', 'uuid']); // also the business_id index
            $table->index(['business_id', 'sync_seq']); // delta pull
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_materials');
    }
};
