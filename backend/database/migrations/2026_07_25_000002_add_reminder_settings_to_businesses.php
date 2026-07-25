<?php
// database/migrations/2026_07_25_000002_add_reminder_settings_to_businesses.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who counts as "overdue" is a per-shop judgement — a wholesaler on 45-day terms
 * and a daily-settling retailer disagree. Two scalars on businesses rather than
 * a settings table: two columns do not earn a table, and businesses is already
 * the home of default_language and plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->table('businesses', function (Blueprint $table) {
            // Don't chase trivial balances. Money is decimal(12,2) app-wide.
            $table->decimal('reminder_min_outstanding', 12, 2)->default('500.00');
            // Days since the last payment before a reminder is reasonable.
            $table->smallInteger('reminder_min_days')->default(30);
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->table('businesses', function (Blueprint $table) {
            $table->dropColumn(['reminder_min_outstanding', 'reminder_min_days']);
        });
    }
};
