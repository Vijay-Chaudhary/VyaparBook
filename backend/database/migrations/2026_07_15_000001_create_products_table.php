<?php
// database/migrations/2026_07_15_000001_create_products_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name_hi', 120);
            $table->string('name_en', 120)->nullable();
            $table->decimal('base_cost_per_kg', 10, 2)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            // Every query on this table filters business_id (RLS adds the predicate
            // even when the app layer doesn't), and Postgres does not index foreign
            // keys automatically. pack_sizes/product_packs get this for free from
            // the leftmost column of their composite unique indexes; products has
            // no such index, so it needs an explicit one.
            $table->index('business_id');
        });

        DB::connection('pgsql_migrate')->statement('ALTER TABLE products ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE products FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY products_isolation ON products
            USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
            WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('products');
    }
};
