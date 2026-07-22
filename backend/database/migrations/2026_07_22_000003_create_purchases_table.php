<?php
// database/migrations/2026_07_22_000003_create_purchases_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('purchases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->uuid('uuid'); // client/idempotency key
            $table->foreignUuid('supplier_id')->constrained('suppliers');
            $table->foreignUuid('raw_material_id')->constrained('raw_materials');
            $table->date('purchase_date');
            $table->decimal('qty', 12, 3);        // kg, mirrors stock_movements.qty
            $table->decimal('unit_cost', 12, 2);  // ₹ per kg
            $table->decimal('total', 12, 2);      // qty × unit_cost, computed server-side
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'uuid']); // also the business_id index
            $table->index(['business_id', 'purchase_date']); // month/valuation queries
            $table->index(['business_id', 'raw_material_id']); // Cost/Kg per material
            $table->index(['business_id', 'supplier_id']); // supplier ledger
        });

        DB::connection('pgsql_migrate')->statement('ALTER TABLE purchases ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE purchases FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY purchases_isolation ON purchases
            USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
            WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('purchases');
    }
};
