<?php
// app/Models/Expense.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    // created_by is absent from $fillable: stamped from app('tenant.user_id'),
    // never taken from request input. Online-only, so no version/sync_seq traits.
    protected $fillable = ['business_id', 'uuid', 'category', 'amount', 'spent_on', 'note'];

    protected $casts = [
        'amount' => 'decimal:2',
        'spent_on' => 'date',
        'archived_at' => 'datetime',
    ];
}
