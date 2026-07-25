<?php
// app/Models/Order.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasSyncSequence;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a shop asked for. Becomes a Sale when delivered — see OrderWriter.
 *
 * created_by, total, status, accepted_by, accepted_at, status_note and sale_id
 * are absent from $fillable: all are stamped by OrderWriter or the accept
 * screen, never taken from a client payload.
 */
class Order extends Model
{
    use BelongsToTenant, HasFactory, HasSyncSequence, HasUuids, HasVersion;

    protected $fillable = ['business_id', 'uuid', 'customer_id', 'order_date'];

    protected $casts = [
        'order_date' => 'date',
        'accepted_at' => 'datetime',
        'total' => 'decimal:2',
        'version' => 'integer',
        'sync_seq' => 'integer',
    ];

    /** @return HasMany<OrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
