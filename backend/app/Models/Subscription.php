<?php
// app/Models/Subscription.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant's current billing state — one row per business. Status and period
 * are updated in place (versioned); the payment history lives in the append-only
 * subscription_payments ledger.
 */
class Subscription extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, HasVersion;

    protected $fillable = ['business_id', 'plan', 'status', 'trial_ends_at', 'current_period_end'];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_end' => 'datetime',
        'version' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class, 'business_id', 'business_id');
    }
}
