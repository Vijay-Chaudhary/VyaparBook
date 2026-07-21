<?php
// app/Models/Sale.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasSyncSequence;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use BelongsToTenant, HasFactory, HasSyncSequence, HasUuids, HasVersion;

    // created_by and total are deliberately absent from $fillable: created_by is
    // stamped from app('tenant.user_id') and total is computed from the lines,
    // never taken from request input. version and sync_seq are trait-managed.
    protected $fillable = ['business_id', 'uuid', 'customer_id', 'sale_date', 'reverses_id'];

    protected $casts = [
        'sale_date' => 'date',
        'total' => 'decimal:2',
        'version' => 'integer',
        'sync_seq' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    /** The original this row voids, when it is itself a reversal. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'reverses_id');
    }
}
