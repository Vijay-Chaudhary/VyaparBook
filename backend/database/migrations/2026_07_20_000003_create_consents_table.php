<?php
// database/migrations/2026_07_20_000003_create_consents_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The DPDP consent ledger (PRD §13): append-only evidence of consent given and
 * withdrawn, per user.
 *
 * Append-only, like platform_audit_logs: each row is an EVENT, never updated.
 * Current consent is the latest event for a user. A mutable boolean on users
 * would answer "do they consent now?" but destroy the record of when consent
 * was given and under which policy version — which is the part that has to
 * stand up to a regulator.
 *
 * Platform-level, not tenant-owned: consent belongs to the person, who may be a
 * member of several businesses. No business_id, no RLS — same as users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('consents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // 'granted' | 'withdrawn'. A string, not a bool, so a future purpose
            // -specific action does not need a schema change.
            $table->string('action', 20);

            // Which notice the person agreed to. Consent to a superseded policy
            // is not consent to the current one, so this must be recorded.
            $table->string('policy_version', 40);

            // Evidence of the affirmative action, captured at the moment it
            // happened. Nullable: a CLI or back-office correction has neither.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('consents');
    }
};
