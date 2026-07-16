<?php
// app/Models/PackSize.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackSize extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, HasVersion;

    protected $fillable = ['business_id', 'label', 'weight_kg', 'in_dropdown'];

    protected $casts = [
        'weight_kg' => 'decimal:3',
        'in_dropdown' => 'boolean',
        'archived_at' => 'datetime',
        'version' => 'integer',
    ];

    public function productPacks(): HasMany
    {
        return $this->hasMany(ProductPack::class);
    }
}
