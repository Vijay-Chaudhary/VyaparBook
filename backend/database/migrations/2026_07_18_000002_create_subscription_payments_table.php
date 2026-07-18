<?php
// database/migrations/2026_07_18_000002_create_subscription_payments_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('subscription_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            // Client-supplied idempotency key — a retried record resolves to the
            // same row, never a duplicate payment. Unique per tenant.
            $table->uuid('uuid');
            $table->string('plan', 20);
            $table->decimal('amount', 12, 2);
            $table->decimal('gst_amount', 12, 2)->default(0);
            $table->string('mode', 20);
            $table->string('reference', 100)->nullable();
            $table->unsignedSmallInteger('period_months');
            // pending → verified | rejected. The platform (Superadmin) sets the
            // terminal states; v1 records pending only.
            $table->string('status', 20)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->string('note', 255)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['business_id', 'uuid']);
        });

        DB::connection('pgsql_migrate')->statement('ALTER TABLE subscription_payments ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE subscription_payments FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY subscription_payments_isolation ON subscription_payments
            USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
            WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('subscription_payments');
    }
};
