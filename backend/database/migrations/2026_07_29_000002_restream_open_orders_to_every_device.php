<?php
// database/migrations/2026_07_29_000002_restream_open_orders_to_every_device.php

use App\Support\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Push every open order over every device's sync cursor.
 *
 * Orders used to stream only to the salesman who took them. Widening that
 * filter does not reach backwards: the delta is `sync_seq > cursor`, so an
 * order accepted last Tuesday sits BELOW a colleague's cursor and would never
 * arrive — and an order waiting to be packed is exactly the case the change
 * exists for.
 *
 * Bumping sync_seq re-streams it on the next pull, using the mechanism as
 * intended: an archive already bumps sync_seq so clients learn to hide a row.
 * No client migration, no Dexie version, no forced full resync.
 *
 * Terminal orders are deliberately left alone. They are history, they cannot be
 * acted on, and re-sending every order a shop has ever completed would make
 * one pull arbitrarily large to no purpose.
 */
return new class extends Migration
{
    /** pending | accepted | packed — the states someone can still act on. */
    private const OPEN = ['pending', 'accepted', 'packed'];

    public function up(): void
    {
        $this->restream();
    }

    /**
     * Public and separate from up() so the status filter — the only part whose
     * error is silent rather than loud — is covered by a test.
     *
     * Lines are lifted too: they now delta independently, so a device that
     * receives an order for the first time must receive its lines in the same
     * pull or show a delivery with nothing in it.
     */
    public function restream(): void
    {
        // Deliberately cross-tenant, and a migration is the one context where
        // that is unremarkable: it runs outside any request, with no tenant to
        // bind, and its whole job is to walk every shop. Explicit so the query
        // tripwire does not have to guess.
        Tenancy::withoutTenant(function () {
            // Postgres did this in two set-based UPDATEs off nextval('sync_seq_global').
            // MySQL has no sequence, and the counter that replaced it is per-tenant,
            // so there is no platform-wide value left to draw from: walk the shops
            // that still have something actionable and reserve a block from each.
            //
            // A shop with no open orders is never visited, which is also why a fresh
            // MySQL build survives this running before sync_sequences exists — there
            // are no rows to restream, so the counter is never asked for a value.
            $businessIds = DB::table('orders')
                ->whereIn('status', self::OPEN)
                ->distinct()
                ->pluck('business_id');

            foreach ($businessIds as $businessId) {
                $orderIds = DB::table('orders')
                    ->where('business_id', $businessId)
                    ->whereIn('status', self::OPEN)
                    ->orderBy('id')
                    ->pluck('id');

                $lineIds = DB::table('order_lines')
                    ->where('business_id', $businessId)
                    ->whereIn('order_id', $orderIds)
                    ->orderBy('id')
                    ->pluck('id');

                // Orders are numbered before their lines, so a line never outranks
                // its own order in the delta.
                $next = $this->reserve($businessId, $orderIds->count() + $lineIds->count());

                foreach ($orderIds as $id) {
                    DB::table('orders')->where('id', $id)->update(['sync_seq' => $next++]);
                }

                foreach ($lineIds as $id) {
                    DB::table('order_lines')->where('id', $id)->update(['sync_seq' => $next++]);
                }
            }
        });
    }

    /**
     * Hand back the first of `$count` consecutive sync_seq values for one tenant.
     *
     * Values are handed out one per row rather than one per table: a device pages
     * the delta by `sync_seq > cursor`, so two rows sharing a value can straddle a
     * page boundary and one of them is never seen again.
     *
     * The counter is dragged up to the tenant's existing high-water mark first.
     * `sync_sequences` starts every shop at 0 while its rows do not, and a value
     * below a cursor a device already holds is a value that never arrives.
     */
    private function reserve(string $businessId, int $count): int
    {
        $highWater = max(
            (int) DB::table('orders')->where('business_id', $businessId)->max('sync_seq'),
            (int) DB::table('order_lines')->where('business_id', $businessId)->max('sync_seq'),
        );

        DB::table('sync_sequences')->insertOrIgnore([
            'business_id' => $businessId,
            'value' => $highWater,
        ]);

        DB::table('sync_sequences')
            ->where('business_id', $businessId)
            ->where('value', '<', $highWater)
            ->update(['value' => $highWater]);

        DB::table('sync_sequences')->where('business_id', $businessId)->increment('value', $count);

        $end = (int) DB::table('sync_sequences')->where('business_id', $businessId)->value('value');

        return $end - $count + 1;
    }

    public function down(): void
    {
        // Irreversible by nature, and harmless: sync_seq is a cursor, not data.
        // Lowering these again would only re-hide rows devices have already read.
    }
};
