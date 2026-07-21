<?php
// app/Models/MaterialConsumption.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasSyncSequence;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialConsumption extends Model
{
    use BelongsToTenant, HasFactory, HasSyncSequence, HasUuids, HasVersion;

    // A child of the batch (like SaleLine): no uuid — it is written in one
    // transaction with its parent, whose (business_id, uuid) makes the batch
    // idempotent. qty is the positive amount consumed; the stock draw-down is a
    // separate signed StockMovement.
    protected $fillable = ['business_id', 'production_batch_id', 'raw_material_id', 'qty'];

    protected $casts = [
        'qty' => 'decimal:3',
        'version' => 'integer',
        'sync_seq' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }
}
