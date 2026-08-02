<?php
// database/migrations/2026_07_29_000003_add_cost_at_sale_to_sale_lines.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the pack cost on the day it was sold.
 *
 * Selling below cost is now allowed rather than refused, which immediately
 * raises the question "what did we sell under cost this month?". That cannot be
 * answered from today's cost: costs move — the shop is about to raise all three
 * of theirs — and re-deriving would silently rewrite last month's answer every
 * time a price is edited.
 *
 * So the cost basis is SNAPSHOT at the moment of sale, exactly as list_rate
 * already is, and for the same reason: a figure used to judge a past decision
 * must be the figure that was true when the decision was made.
 *
 * Nullable and NEVER backfilled. Rows written before this have no honest value
 * — the cost then is not the cost now — and inventing one would produce an
 * authoritative-looking below-cost report that is simply wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->decimal('cost_at_sale', 10, 2)->nullable()->after('list_rate');
        });
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropColumn('cost_at_sale');
        });
    }
};
