<?php
// app/Models/Beat.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasSyncSequence;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named set of customers worked on fixed weekdays (PRD Phase 3).
 *
 * Offline-synced but SERVER-WRITTEN only: the phone reads beats and never
 * writes them, so there is no push path or conflict rule here.
 */
class Beat extends Model
{
    use BelongsToTenant, HasFactory, HasSyncSequence, HasUuids, HasVersion;

    protected $fillable = ['business_id', 'name', 'weekdays', 'assigned_user_id'];

    protected $casts = [
        'weekdays' => 'array',          // ISO weekdays, 1 = Monday … 7 = Sunday
        'assigned_user_id' => 'integer',
        'archived_at' => 'datetime',
        'version' => 'integer',
        'sync_seq' => 'integer',
    ];

    /** @return HasMany<BeatCustomer, $this> */
    public function beatCustomers(): HasMany
    {
        return $this->hasMany(BeatCustomer::class)->orderBy('position');
    }
}
