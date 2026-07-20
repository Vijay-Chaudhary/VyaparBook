<?php
// app/Models/Consent.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One consent event. Append-only: rows are inserted, never updated or deleted,
 * so only created_at is maintained (there is no updated_at to maintain).
 */
class Consent extends Model
{
    use HasFactory, HasUuids;

    public const GRANTED = 'granted';

    public const WITHDRAWN = 'withdrawn';

    public $timestamps = false;

    protected $fillable = ['user_id', 'action', 'policy_version', 'ip_address', 'user_agent'];

    protected static function booted(): void
    {
        static::creating(function (Consent $consent) {
            $consent->created_at ??= now();
        });

        // Append-only is enforced here, not merely documented: an accidental
        // update or delete would rewrite the evidence the ledger exists to hold.
        static::updating(fn () => throw new \LogicException('Consent records are append-only.'));
        static::deleting(fn () => throw new \LogicException('Consent records are append-only.'));
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
