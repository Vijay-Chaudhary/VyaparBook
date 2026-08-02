<?php
// database/migrations/2026_07_17_000004_create_sale_lines_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // business_id is carried directly (not reached through sale_id) so the
            // tenant predicate stays flat and needs no join. No `uuid`: a line is
            // never created independently offline — it is written in one
            // transaction with its parent Sale, whose (business_id, uuid) already
            // makes the whole sale idempotent.
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('sale_id')->constrained('sales')->cascadeOnDelete();
            // No cascadeOnDelete: the catalog archives packs, never deletes them,
            // so a line's pack is always resolvable for a historical sale.
            $table->foreignUuid('product_pack_id')->constrained('product_packs');
            $table->integer('qty'); // negative qty = a return line (PRD §7 returns)
            // Price SNAPSHOT — frozen from the pack at sale time, never read live.
            // A two-year-old sale must reflect the price then, not today's catalog.
            $table->decimal('rate', 10, 2);
            $table->decimal('line_total', 12, 2); // rate * qty, stored immutable
            $table->unsignedInteger('version')->default(1);
            $table->bigInteger('sync_seq');
            $table->timestamps();

            $table->index(['business_id', 'sale_id']);
            $table->index(['business_id', 'sync_seq']); // delta pull streams lines too
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_lines');
    }
};
