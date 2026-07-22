<?php
// app/Models/SupplierPayment.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    // created_by is stamped from app('tenant.user_id'), never filled.
    protected $fillable = ['business_id', 'uuid', 'supplier_id', 'payment_date', 'amount', 'mode', 'note'];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'archived_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
