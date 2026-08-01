<?php
// database/migrations/2026_07_25_000003_add_reminder_opt_out_to_customers.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-customer messaging opt-out (PRD §13 DPDP).
 *
 * The existing `consents` table is platform-level and keyed to user_id — it
 * records owners and staff accepting the privacy policy. A shop's CUSTOMER is a
 * different data subject entirely and has no record there, so reminding them
 * needs its own mechanism. A nullable timestamp (not a boolean) so "when did
 * they opt out" survives, consistent with how archived_at is used elsewhere.
 *
 * customers is an offline-synced table, but this column is deliberately NOT
 * added to KhataController's API whitelist: it is owner-side only and must not
 * change the sync payload in this phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('reminder_opt_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('reminder_opt_out_at');
        });
    }
};
