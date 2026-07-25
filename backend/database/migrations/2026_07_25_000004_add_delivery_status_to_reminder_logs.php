<?php
// database/migrations/2026_07_25_000004_add_delivery_status_to_reminder_logs.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4b: delivery status on the existing 4a intent log.
 *
 * Status only ever moves FORWARD (queued → sent → delivered → read, or failed).
 * Meta delivers callbacks out of order, so a late 'sent' arriving after
 * 'delivered' must not rewind the row — that ordering rule lives in the webhook
 * controller, and these columns are what it writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->table('reminder_logs', function (Blueprint $table) {
            $table->string('status', 12)->default('queued');
            $table->timestamp('status_at')->nullable();
            // The webhook's only handle on a row — Meta quotes this id back.
            $table->string('provider_message_id', 128)->nullable();
            $table->string('error_code', 32)->nullable();
            $table->string('error_message', 255)->nullable();

            $table->index('provider_message_id');
        });

        // Phase 4a rows were handed to the owner's own WhatsApp: 'sent' is as
        // much as that channel can ever report, and leaving them 'queued' would
        // misrepresent them as still in flight.
        DB::connection('pgsql_migrate')->table('reminder_logs')
            ->where('channel', 'wa_link')
            ->update(['status' => 'sent']);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->table('reminder_logs', function (Blueprint $table) {
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn(['status', 'status_at', 'provider_message_id', 'error_code', 'error_message']);
        });
    }
};
