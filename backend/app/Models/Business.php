<?php
// app/Models/Business.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'city', 'gstin', 'default_language', 'plan'];

    protected $casts = [
        // Phase 4a reminder thresholds: who counts as overdue, per shop.
        'reminder_min_outstanding' => 'decimal:2',
        'reminder_min_days' => 'integer',
        // Phase 4c automation settings. auto_enabled defaults false: a shop must
        // never discover this feature by having it message their customers.
        'reminder_auto_enabled' => 'boolean',
        'reminder_cooldown_days' => 'integer',
        'reminder_daily_cap' => 'integer',
    ];
}
