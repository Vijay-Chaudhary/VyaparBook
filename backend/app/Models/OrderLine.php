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

/** One line of an order. line_total is stamped, never filled. */
class OrderLine extends Model
{
    use BelongsToTenant, HasFactory, HasSyncSequence, HasUuids, HasVersion;

    protected $fillable = ['business_id', 'order_id', 'product_pack_id', 'qty', 'rate'];

    protected $casts = [
        'qty' => 'integer',
        'rate' => 'decimal:2',
        'line_total' => 'decimal:2',
        'version' => 'integer',
        'sync_seq' => 'integer',
    ];

    /** @return BelongsTo<ProductPack, $this> */
    public function productPack(): BelongsTo
    {
        return $this->belongsTo(ProductPack::class);
    }
}
