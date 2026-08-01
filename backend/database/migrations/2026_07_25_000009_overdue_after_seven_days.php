<?php
// database/migrations/2026_07_25_000009_overdue_after_seven_days.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Overdue" now means 7 days without payment, not 30.
 *
 * 30 was a conservative guess when reminders were first built (Phase 4a). For a
 * daily-settling distribution business a week is the real answer: a customer who
 * has not paid in a week is who the owner actually wants to chase.
 *
 * Existing rows are moved too, deliberately. reminder_min_days had NO user
 * interface until now, so no shop can have chosen 30 — every row holds it only
 * because it was the default. Migrating them is therefore correcting a default,
 * not overriding anybody's decision. Rows holding anything else are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE businesses ALTER COLUMN reminder_min_days SET DEFAULT 7'
        );

        DB::table('businesses')
            ->where('reminder_min_days', 30)
            ->update(['reminder_min_days' => 7]);
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE businesses ALTER COLUMN reminder_min_days SET DEFAULT 30'
        );

        DB::table('businesses')
            ->where('reminder_min_days', 7)
            ->update(['reminder_min_days' => 30]);
    }
};
