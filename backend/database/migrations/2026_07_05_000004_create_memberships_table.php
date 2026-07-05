<?php
// database/migrations/2026_07_05_000004_create_memberships_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->enum('role', ['owner', 'admin', 'salesman', 'accountant']);
            $table->timestamps();
            $table->unique(['user_id', 'business_id']);
        });

        DB::connection('pgsql_migrate')->statement('ALTER TABLE memberships ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE memberships FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY membership_isolation ON memberships
            USING (
                user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint
                OR business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid
            )
            WITH CHECK (
                business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid
            )
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('memberships');
    }
};
