<?php
// app/Models/Product.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, HasVersion;

    // archived_at and version are deliberately absent: they are set by explicit
    // assignment, never mass-assigned. business_id is present because factories
    // fill through $fillable — see the plan's File Structure note.
    protected $fillable = ['business_id', 'name_hi', 'name_en', 'base_cost_per_kg'];

    protected $casts = [
        'base_cost_per_kg' => 'decimal:2',
        'archived_at' => 'datetime',
        'version' => 'integer',
    ];

    public function productPacks(): HasMany
    {
        return $this->hasMany(ProductPack::class);
    }
}
