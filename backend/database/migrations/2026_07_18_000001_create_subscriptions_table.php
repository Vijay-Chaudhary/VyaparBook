<?php
// database/migrations/2026_07_18_000001_create_subscriptions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // One subscription per business — current state, not a ledger.
            $table->foreignUuid('business_id')->unique()->constrained('businesses')->cascadeOnDelete();
            $table->string('plan', 20);
            $table->string('status', 20);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_end')->nullable();
            // Updated in place under HasVersion. No uuid/sync_seq: billing is
            // online-only in v1 and never rides the offline delta.
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        DB::connection('pgsql_migrate')->statement('ALTER TABLE subscriptions ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE subscriptions FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY subscriptions_isolation ON subscriptions
            USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
            WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('subscriptions');
    }
};
