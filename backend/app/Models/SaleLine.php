<?php
// app/Models/SaleLine.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasSyncSequence;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleLine extends Model
{
    use BelongsToTenant, HasFactory, HasSyncSequence, HasUuids, HasVersion;

    // line_total is absent from $fillable: it is rate * qty, computed at write
    // time and stored immutable, never taken from request input.
    protected $fillable = ['business_id', 'sale_id', 'product_pack_id', 'qty', 'rate'];

    protected $casts = [
        'qty' => 'integer',
        'rate' => 'decimal:2',
        'list_rate' => 'decimal:2',
        // What the pack cost the day it sold. Snapshot, not re-derived: costs
        // move, and "did we sell this under cost?" must keep the answer it had
        // at the time. Null on rows written before it was recorded — unknown,
        // never "it was fine".
        'cost_at_sale' => 'decimal:2',
        'line_total' => 'decimal:2',
        'version' => 'integer',
        'sync_seq' => 'integer',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function productPack(): BelongsTo
    {
        return $this->belongsTo(ProductPack::class);
    }
}
