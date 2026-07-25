<?php
// app/Models/Invoice.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A filed tax invoice: immutable by design (PRD Phase 3 / GST spec Decision 5).
 * Everything printed on it is snapshotted here or on its lines, never read live
 * from products or the business, so a later edit cannot change a filed document.
 */
class Invoice extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'business_id', 'sale_id', 'number', 'financial_year', 'seq', 'issued_on',
        'buyer_name', 'buyer_village', 'buyer_gstin', 'seller_gstin', 'seller_state_code',
        'taxable_total', 'cgst_total', 'sgst_total', 'grand_total',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'seq' => 'integer',
        'taxable_total' => 'decimal:2',
        'cgst_total' => 'decimal:2',
        'sgst_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
    ];

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
