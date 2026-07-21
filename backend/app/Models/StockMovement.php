<?php
// app/Models/StockMovement.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasSyncSequence;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use BelongsToTenant, HasFactory, HasSyncSequence, HasUuids, HasVersion;

    // created_by is absent from $fillable: it is stamped from app('tenant.user_id'),
    // never taken from request input. version and sync_seq are trait-managed.
    // production_batch_id is fillable so ProductionWriter can set it; it stays null
    // for movements recorded by hand.
    protected $fillable = ['business_id', 'uuid', 'raw_material_id', 'movement_date', 'kind', 'qty', 'note', 'production_batch_id'];

    protected $casts = [
        'movement_date' => 'date',
        'qty' => 'decimal:3',
        'version' => 'integer',
        'sync_seq' => 'integer',
    ];

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    /** The batch whose completion wrote this `out` movement, when it has one. */
    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }
}
