<?php
// database/migrations/2026_07_17_000002_create_customers_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            // Client-generated idempotency key: a customer created offline carries
            // its uuid so a retried create resolves to the same row, never a
            // duplicate. (business_id, uuid) is unique per tenant.
            $table->uuid('uuid');
            $table->string('name', 120);
            $table->string('village', 80)->nullable();
            $table->string('phone', 20)->nullable();
            // The khata's starting point: what the customer owed before VyaparBook.
            // Money is decimal(12,2) across khata — a distributor's running total
            // dwarfs a single catalog price, so the catalog's 10,2 is widened here.
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->bigInteger('sync_seq');
            $table->timestamps();

            // (business_id, uuid) also serves as the business_id index (leftmost).
            $table->unique(['business_id', 'uuid']);
            // Delta pull scans business_id + sync_seq > cursor, ordered by sync_seq.
            $table->index(['business_id', 'sync_seq']);
        });

        DB::connection('pgsql_migrate')->statement('ALTER TABLE customers ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE customers FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY customers_isolation ON customers
            USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
            WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('customers');
    }
};
