<?php
// app/Models/Customer.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasSyncSequence;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToTenant, HasFactory, HasSyncSequence, HasUuids, HasVersion;

    // archived_at, version and sync_seq are deliberately absent: version and
    // sync_seq are stamped by their traits and archived_at by explicit assignment.
    // business_id and uuid are fillable because factories fill through $fillable
    // and the controller passes uuid from the client — see the plan's note.
    protected $fillable = ['business_id', 'uuid', 'name', 'village', 'phone', 'opening_balance'];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'archived_at' => 'datetime',
        'version' => 'integer',
        'sync_seq' => 'integer',
    ];

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
