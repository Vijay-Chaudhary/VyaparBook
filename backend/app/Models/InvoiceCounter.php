<?php
// app/Models/InvoiceCounter.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The gapless-numbering counter, one row per business per financial year.
 * Locked FOR UPDATE during allocation — see InvoiceIssuer.
 */
class InvoiceCounter extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['business_id', 'financial_year', 'next_seq'];

    protected $casts = ['next_seq' => 'integer'];
}
