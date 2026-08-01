<?php
// database/migrations/2026_07_25_000008_create_platform_whatsapp_settings_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's single WhatsApp Cloud API configuration.
 *
 * Platform-owned, NOT a tenant table — no RLS, no BelongsToTenant, like
 * platform_audit_logs. There is exactly one row because there is exactly one
 * WhatsApp number (Phase 4a Decision 3: no per-tenant credentials).
 *
 * token / verify_token / app_secret are written through Laravel's `encrypted`
 * cast, so this table never holds them in plaintext — which is why they are
 * text rather than sized strings (ciphertext is longer than the secret).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_whatsapp_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('driver', 12)->default('log');
            $table->string('api_version', 12)->nullable();
            $table->string('phone_number_id', 64)->nullable();
            $table->text('token')->nullable();
            $table->string('template', 64)->nullable();
            $table->text('verify_token')->nullable();
            $table->text('app_secret')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_whatsapp_settings');
    }
};
