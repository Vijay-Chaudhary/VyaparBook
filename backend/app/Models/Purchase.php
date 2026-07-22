<?php
// app/Models/Purchase.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Purchase extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    // created_by is stamped from app('tenant.user_id'), never filled. total is
    // computed server-side (qty × unit_cost), never taken from request input.
    protected $fillable = [
        'business_id', 'uuid', 'supplier_id', 'raw_material_id',
        'purchase_date', 'qty', 'unit_cost', 'total', 'note',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'total' => 'decimal:2',
        'purchase_date' => 'date',
        'archived_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }
}
