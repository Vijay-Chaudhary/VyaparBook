<?php
// app/Models/ReminderLog.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Evidence that a payment reminder was SENT — intent, not delivery.
 *
 * A wa.me link is fire-and-forget: we hand the owner a prefilled WhatsApp
 * message and cannot observe whether they pressed send, let alone whether it
 * arrived. So a row here means "the owner triggered a reminder", and
 * amount_at_send freezes what was claimed at that moment. Phase 4b hangs real
 * delivery status off the same row.
 *
 * Never an input to outstanding — outstanding stays derived from sales and
 * payments alone (PRD §9).
 */
class ReminderLog extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    // created_by is absent: stamped from app('tenant.user_id'), never request
    // input. Online-only, so no version/sync_seq traits.
    protected $fillable = ['business_id', 'customer_id', 'channel', 'amount_at_send', 'locale', 'phone_e164'];

    protected $casts = [
        'amount_at_send' => 'decimal:2',
    ];
}
