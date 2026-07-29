<?php
// app/Models/ProductionBatch.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasSyncSequence;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionBatch extends Model
{
    use BelongsToTenant, HasFactory, HasSyncSequence, HasUuids, HasVersion;

    // created_by is absent from $fillable: it is stamped from app('tenant.user_id'),
    // never taken from request input. version and sync_seq are trait-managed.
    protected $fillable = ['business_id', 'uuid', 'product_id', 'batch_date', 'output_kg'];

    protected $casts = [
        'batch_date' => 'date',
        'output_kg' => 'decimal:3',
        'version' => 'integer',
        'sync_seq' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(MaterialConsumption::class);
    }

    /**
     * The stock movements this batch caused — the draw-down of each material it
     * consumed. Reversing a batch has to put those back, and they are the only
     * record of how much actually left stock.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
