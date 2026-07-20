<?php
// database/migrations/2026_07_20_000001_allow_system_actor_on_platform_audit_logs.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the platform trail record actions with no logged-in console admin.
 *
 * The DPDP tools (tenant:export, tenant:erase) run on the box as operator
 * commands, not through the console, so auth()->id() is null — and a NOT NULL
 * admin_user_id would make an audited CLI action impossible to write. These are
 * precisely the actions that most need a trail, so the column gives way rather
 * than the audit. Metadata carries 'via' => 'cli' so a null actor is legible
 * rather than looking like a bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->table('platform_audit_logs', function (Blueprint $table) {
            $table->foreignId('admin_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->table('platform_audit_logs', function (Blueprint $table) {
            $table->foreignId('admin_user_id')->nullable(false)->change();
        });
    }
};
