<?php
// database/migrations/2026_07_22_000001_create_expenses_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            // Client/idempotency key: a retried create resolves to the same row.
            $table->uuid('uuid');
            // Operating-expense category; validated against App\Expenses\ExpenseCategory.
            $table->string('category', 20);
            // Money is decimal(12,2) across the app; bcmath scale-2 strings.
            $table->decimal('amount', 12, 2);
            // The day the expense belongs to — drives month/year grouping.
            $table->date('spent_on');
            $table->string('note', 255)->nullable();
            // users.id is a bigint ($table->id()); created_by matches every other
            // tenant table (sales/payments/production_batches all use foreignId).
            $table->foreignId('created_by')->constrained('users');
            // Soft-delete: edit/delete archives; archived rows excluded from reads.
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // (business_id, uuid) unique + leftmost business_id index.
            $table->unique(['business_id', 'uuid']);
            // Month/trend queries scan business_id + spent_on.
            $table->index(['business_id', 'spent_on']);
        });

        // Online-only Blade table: NO version/sync_seq — never enters offline sync.

        DB::connection('pgsql_migrate')->statement('ALTER TABLE expenses ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE expenses FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY expenses_isolation ON expenses
            USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
            WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('expenses');
    }
};
