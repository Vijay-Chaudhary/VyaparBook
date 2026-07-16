<?php
// app/Models/ProductPack.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPack extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, HasVersion;

    protected $fillable = [
        'business_id', 'product_id', 'pack_size_id',
        'default_sell_price', 'default_cost_price',
    ];

    protected $casts = [
        'default_sell_price' => 'decimal:2',
        'default_cost_price' => 'decimal:2',
        'archived_at' => 'datetime',
        'version' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function packSize(): BelongsTo
    {
        return $this->belongsTo(PackSize::class);
    }
}
