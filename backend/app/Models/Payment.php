<?php
// app/Models/Payment.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasSyncSequence;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    // SoftDeletes for the same reason as Sale: the owner deletes a payment off
    // the khata and the global scope keeps it out of outstanding and cash flow,
    // while the row itself stays restorable.
    use BelongsToTenant, HasFactory, HasSyncSequence, HasUuids, HasVersion, SoftDeletes;

    // created_by is absent from $fillable: it is stamped from app('tenant.user_id'),
    // never taken from request input. version and sync_seq are trait-managed.
    protected $fillable = ['business_id', 'uuid', 'customer_id', 'payment_date', 'amount', 'mode', 'reverses_id'];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'version' => 'integer',
        'sync_seq' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The original this row reverses, when it is itself a correction. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'reverses_id');
    }
}
