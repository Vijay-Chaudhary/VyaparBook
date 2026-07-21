<?php
// database/migrations/2026_07_19_000002_create_platform_audit_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's append-only mutation trail: which admin did what to which tenant.
 * Platform-owned, NOT a tenant table — no RLS, no BelongsToTenant, no version/
 * sync_seq. Rows are inserted, never updated or deleted (created_at only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('platform_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('admin_user_id')->constrained('users');
            $table->string('action', 40);
            $table->foreignUuid('target_business_id')->nullable()->constrained('businesses');
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['target_business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('platform_audit_logs');
    }
};
