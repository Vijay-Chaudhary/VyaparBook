<?php
// app/Models/PlatformAuditLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of a platform admin acting on a tenant. Not a tenant table:
 * no BelongsToTenant scope, no RLS — the platform owns it. Only created_at is
 * kept (set explicitly), so timestamps are disabled.
 */
class PlatformAuditLog extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['admin_user_id', 'action', 'target_business_id', 'metadata'];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        // created_at is the only timestamp and is not fillable, so stamp it here —
        // covers the helper, factories and any direct create uniformly.
        static::creating(function (self $log) {
            if ($log->created_at === null) {
                $log->created_at = now();
            }
        });
    }
}
