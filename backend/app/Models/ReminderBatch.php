<?php
// app/Models/ReminderBatch.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One scheduled reminder run for one tenant on one day (Phase 4c).
 *
 * The messages live in reminder_logs; this records what the run itself did,
 * including why it stopped — a run that sends nothing must always be able to
 * say why, or the owner is left guessing whether the feature works.
 */
class ReminderBatch extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['business_id', 'scheduled_for', 'status', 'planned_count', 'sent_count', 'stopped_reason'];

    protected $casts = [
        'scheduled_for' => 'date',
        'planned_count' => 'integer',
        'sent_count' => 'integer',
    ];

    /** @return HasMany<ReminderLog, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(ReminderLog::class, 'batch_id');
    }
}
