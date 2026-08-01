<?php
// database/migrations/2026_07_15_000002_create_pack_sizes_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pack_sizes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('label', 40);
            $table->decimal('weight_kg', 8, 3);
            $table->boolean('in_dropdown')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            // Also serves as the business_id index (leftmost column).
            $table->unique(['business_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pack_sizes');
    }
};
