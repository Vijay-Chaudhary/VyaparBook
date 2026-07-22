<?php
// app/Models/Supplier.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    // Online-only Blade master record: no version/sync_seq traits.
    protected $fillable = ['business_id', 'uuid', 'name', 'village', 'phone', 'opening_balance'];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'archived_at' => 'datetime',
    ];

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
