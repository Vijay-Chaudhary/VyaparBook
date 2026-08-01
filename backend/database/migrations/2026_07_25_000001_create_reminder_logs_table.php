<?php
// database/migrations/2026_07_25_000001_create_reminder_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            // How it was sent: 'wa_link' (Phase 4a, owner's own WhatsApp via a
            // wa.me deep link) or 'cloud_api' (Phase 4b, platform number).
            $table->string('channel', 20);
            // What the customer owed at the moment of reminding. Frozen, not
            // re-derived later: the log is evidence of what was said.
            $table->decimal('amount_at_send', 12, 2);
            // The language the customer was messaged in (the tenant's, not the
            // owner's UI locale) — the customer is the one reading it.
            $table->string('locale', 5);
            // Normalised destination. Nullable so 4b can log an attempt that
            // failed normalisation without inventing a number.
            $table->string('phone_e164', 20)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            // Drives the "already reminded today" check.
            $table->index(['business_id', 'customer_id', 'created_at']);
        });

        // Online-only Blade table: NO version/sync_seq — never enters offline sync.
        // No (business_id, uuid) idempotency key either: a deliberate re-send is
        // legitimate, so replays must NOT collapse into one row.
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_logs');
    }
};
