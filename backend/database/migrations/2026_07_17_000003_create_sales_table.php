<?php
// database/migrations/2026_07_17_000003_create_sales_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            // Client-generated idempotency key: a sale retried over a flaky link
            // resolves to the same row, never a double-post. Unique per tenant.
            $table->uuid('uuid');
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->date('sale_date');
            // Who rang it up — stamped from app('tenant.user_id'), never the client.
            // foreignId (bigint), not foreignUuid: users.id is a bigint auto-key,
            // unlike the uuid PKs on tenant-domain tables (memberships.user_id
            // references it the same way).
            $table->foreignId('created_by')->constrained('users');
            // Denormalized Σ line_total, stored not computed: the khata sums many
            // sales per customer and must not re-join sale_lines to do it. The
            // service asserts total = Σ line_total at write time.
            $table->decimal('total', 12, 2);
            // Append-only void: a reversal is a NEW sale row pointing back here.
            // Null on an original; set on its reversal. Originals are never mutated.
            // The self-referencing FK is added below, after the table (and its PK)
            // exists — Postgres cannot reference sales(id) while creating sales.
            $table->uuid('reverses_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->bigInteger('sync_seq');
            $table->timestamps();

            $table->unique(['business_id', 'uuid']); // also the business_id index
            $table->index(['business_id', 'customer_id']); // khata per customer
            $table->index(['business_id', 'sync_seq']); // delta pull
        });

        // Self-referencing FK added now that sales(id) exists as a PK.
        Schema::connection('pgsql_migrate')->table('sales', function (Blueprint $table) {
            $table->foreign('reverses_id')->references('id')->on('sales');
        });

        DB::connection('pgsql_migrate')->statement('ALTER TABLE sales ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE sales FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY sales_isolation ON sales
            USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
            WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('sales');
    }
};
