<?php
// database/migrations/2026_07_22_000002_create_suppliers_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->uuid('uuid'); // client/idempotency key
            $table->string('name', 120);
            $table->string('village', 80)->nullable();
            $table->string('phone', 20)->nullable();
            // What the business owed this supplier before VyaparBook (the mirror of
            // customers.opening_balance, on the payables side).
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // Online-only Blade master record: no version/sync_seq (never synced).
            $table->unique(['business_id', 'uuid']); // also the business_id index
        });

        DB::connection('pgsql_migrate')->statement('ALTER TABLE suppliers ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE suppliers FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY suppliers_isolation ON suppliers
            USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
            WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('suppliers');
    }
};
