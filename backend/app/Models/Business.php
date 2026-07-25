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
    ];
}
