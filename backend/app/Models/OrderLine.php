<?php
// app/Models/OrderLine.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasSyncSequence;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an order. line_total is stamped, never filled.
 *
 * So are ordered_qty/ordered_rate — what the salesman asked for, before the
 * owner's acceptance edited qty/rate in place. They are kept out of $fillable
 * for the same reason sale_lines.list_rate is server-authored: the value exists
 * to hold the field to what it promised, so the field must not be able to write
 * it. See App\Orders\OrderAdjustment for how the two pairs are compared.
 */
class OrderLine extends Model
{
    use BelongsToTenant, HasFactory, HasSyncSequence, HasUuids, HasVersion;

    protected $fillable = ['business_id', 'order_id', 'product_pack_id', 'qty', 'rate'];

    protected $casts = [
        'qty' => 'integer',
        'rate' => 'decimal:2',
        'line_total' => 'decimal:2',
        // Nullable: null means the line predates the audit trail, NOT that
        // nothing changed. Never backfilled outside the pending-only case in
        // the migration that added it.
        'ordered_qty' => 'integer',
        'ordered_rate' => 'decimal:2',
        'version' => 'integer',
        'sync_seq' => 'integer',
    ];

    /** @return BelongsTo<ProductPack, $this> */
    public function productPack(): BelongsTo
    {
        return $this->belongsTo(ProductPack::class);
    }
}
