<?php
// database/migrations/2026_07_17_100004_create_material_consumptions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('material_consumptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // business_id is carried directly (not reached through the batch) so the
            // RLS predicate stays flat and needs no join. No `uuid`: a consumption
            // is never created independently offline — it is written in one
            // transaction with its parent batch, whose (business_id, uuid) already
            // makes the whole batch idempotent (mirrors sale_lines).
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('production_batch_id')->constrained('production_batches')->cascadeOnDelete();
            // No cascadeOnDelete: materials are archived, never deleted.
            $table->foreignUuid('raw_material_id')->constrained('raw_materials');
            $table->decimal('qty', 12, 3); // positive amount consumed (draw-down is on the movement)
            $table->unsignedInteger('version')->default(1);
            $table->bigInteger('sync_seq');
            $table->timestamps();

            $table->index(['business_id', 'production_batch_id']);
            $table->index(['business_id', 'sync_seq']); // delta pull streams consumptions too
        });

        DB::connection('pgsql_migrate')->statement('ALTER TABLE material_consumptions ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE material_consumptions FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY material_consumptions_isolation ON material_consumptions
            USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
            WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('material_consumptions');
    }
};
