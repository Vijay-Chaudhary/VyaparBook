<?php
// database/migrations/2026_07_29_000002_restream_open_orders_to_every_device.php

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
        $conn = DB::connection('pgsql_migrate');

        // Ordered so a line never outranks its own order in the delta.
        $conn->statement(
            "UPDATE orders SET sync_seq = nextval('sync_seq_global') WHERE status IN (?, ?, ?)",
            self::OPEN
        );

        $conn->statement(
            "UPDATE order_lines SET sync_seq = nextval('sync_seq_global')
             WHERE order_id IN (SELECT id FROM orders WHERE status IN (?, ?, ?))",
            self::OPEN
        );
    }

    public function down(): void
    {
        // Irreversible by nature, and harmless: sync_seq is a cursor, not data.
        // Lowering these again would only re-hide rows devices have already read.
    }
};
