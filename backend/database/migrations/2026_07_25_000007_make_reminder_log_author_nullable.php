<?php
// database/migrations/2026_07_25_000007_make_reminder_log_author_nullable.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4c: a scheduled reminder has no human author.
 *
 * created_by was NOT NULL in 4a, when every reminder came from an owner tapping
 * a button. The scheduler acts on the tenant's behalf with no user in the
 * request, and stamping the owner's id would put a person's name on something
 * they did not do — which is precisely what an audit column must not do.
 *
 * So: nullable, where null means "the scheduler". Manual sends still record who.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ->change() rather than a raw ALTER: MySQL's MODIFY needs the whole
        // column restated, and Laravel already knows how to spell it.
        Schema::table('reminder_logs', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Scheduler-authored rows have no user to attribute, so they must go
        // before the column can be NOT NULL again.
        DB::table('reminder_logs')->whereNull('created_by')->delete();

        Schema::table('reminder_logs', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable(false)->change();
        });
    }
};
