<?php
// app/Models/BeatCustomer.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasSyncSequence;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One customer's place in a beat's call order. */
class BeatCustomer extends Model
{
    use BelongsToTenant, HasFactory, HasSyncSequence, HasUuids, HasVersion;

    protected $fillable = ['business_id', 'beat_id', 'customer_id', 'position'];

    protected $casts = [
        'position' => 'integer',
        'version' => 'integer',
        'sync_seq' => 'integer',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
