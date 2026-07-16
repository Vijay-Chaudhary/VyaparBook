<?php
// database/migrations/2026_07_15_000003_create_product_packs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('product_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('pack_size_id')->constrained('pack_sizes')->cascadeOnDelete();
            $table->decimal('default_sell_price', 10, 2);
            $table->decimal('default_cost_price', 10, 2)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            // Also serves as the business_id index (leftmost column).
            $table->unique(['business_id', 'product_id', 'pack_size_id']);
        });

        DB::connection('pgsql_migrate')->statement('ALTER TABLE product_packs ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE product_packs FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY product_packs_isolation ON product_packs
            USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
            WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('product_packs');
    }
};
