<?php
// app/Models/SubscriptionPayment.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only manual/UPI payment ledger. A wrong payment is 'rejected' and a new
 * one recorded — never an edited amount, mirroring the khata/stock ledgers.
 * verified_at/verified_by are stamped by SubscriptionService, not fillable.
 */
class SubscriptionPayment extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, HasVersion;

    protected $fillable = [
        'business_id', 'uuid', 'plan', 'amount', 'gst_amount',
        'mode', 'reference', 'period_months', 'status', 'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'verified_at' => 'datetime',
        'period_months' => 'integer',
        'version' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
