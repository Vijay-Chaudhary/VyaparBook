<?php
// database/migrations/2026_07_25_000005_add_reminder_automation_to_businesses.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4c: per-tenant reminder automation settings.
 *
 * reminder_auto_enabled defaults FALSE deliberately. A shop must never discover
 * this feature by having it message their customers — enabling it is an
 * explicit act, taken on a form that says so in plain language.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('reminder_auto_enabled')->default(false);
            // When inside the global 09:00–20:00 quiet-hours window to send.
            $table->time('reminder_send_at')->default('10:00:00');
            // A customer cannot be AUTO-reminded again within this many days.
            // A manual tap by the owner is a human decision and is not blocked.
            $table->smallInteger('reminder_cooldown_days')->default(7);
            // Every automated message bills the platform, not the shop: this
            // bounds worst-case spend for one tenant in one day.
            $table->smallInteger('reminder_daily_cap')->default(25);
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'reminder_auto_enabled', 'reminder_send_at',
                'reminder_cooldown_days', 'reminder_daily_cap',
            ]);
        });
    }
};
